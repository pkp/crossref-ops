<?php

/**
 * @file plugins/generic/crossref/CrossRefPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossRefPlugin
 * @brief Plugin to let managers deposit DOIs and metadata to Crossref
 *
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\IDoiRegistrationAgency;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\HookRegistry;
use PKP\plugins\PluginRegistry;

class CrossrefPlugin extends GenericPlugin implements IDoiRegistrationAgency
{

    private ?CrossRefExportPlugin $_exportPlugin = null;

    public function getDisplayName() : string
    {
        return __('plugins.generic.crossref.displayName');
    }

    public function getDescription() : string
    {
        return __('plugins.generic.crossref.description');
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // If the system isn't installed, or is performing an upgrade, don't
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
                return true;
            }

            if ($this->getEnabled($mainContextId)) {
                $this->_pluginInitialization();
            }
        }

        return $success;
    }

    /**
     * Remove plugin as configured registration agency if set at the time plugin is disabled.
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled)
    {
        parent::setEnabled($enabled);
        if (!$enabled) {
            $contextId = $this->getCurrentContextId();
            /** @var \PKP\context\ContextDAO $contextDao */
            $contextDao = \APP\core\Application::getContextDAO();
            $context = $contextDao->getById($contextId);
            if ($context->getData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY) === $this->getName()) {
                $context->setData(Context::SETTING_CONFIGURED_REGISTRATION_AGENCY, Context::SETTING_NO_REGISTRATION_AGENCY);
                $contextDao->updateObject($context);
            }
        }
    }

    /**
     * Helper to register hooks that are used in normal plugin setup and in CLI tool usage.
     */
    private function _pluginInitialization()
    {
        PluginRegistry::register('importexport', new CrossRefExportPlugin(), $this->getPluginPath());

        Hook::add('Template::doiManagement', array($this, 'callbackShowDoiManagementTabs'));
        Hook::add('DoiSettingsForm::setEnabledRegistrationAgencies', [$this, 'addAsRegistrationAgencyOption']);
        Hook::add('Schema::get::doi', [$this, 'addToSchema']);

        Hook::add('Doi::markRegistered', [$this, 'editMarkRegisteredParams']);
        Hook::add('DoiListPanel::setConfig', [$this, 'addRegistrationAgencyName']);

    }

    /**
     * Extend the website settings tabs to include static pages
     *
     * @param string $hookName The name of the invoked hook
     * @param array $args Hook parameters
     * @return boolean Hook handling status
     */
    public function callbackShowDoiManagementTabs($hookName, $args)
    {
        $templateMgr = $args[1];
        $output =& $args[2];
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        if ($context->getData('registrationAgency') === $this->getName()) {
            $output .= $templateMgr->fetch($this->getTemplateResource('crossrefSettingsTab.tpl'));
        }

        // Permit other plugins to continue interacting with this hook
        return false;
    }

    /**
     * Add properties for Crossref to the DOI entity for storage in the database.
     *
     * @param string $hookName `Schema::get::doi`
     * @param array $args [
     *      @option stdClass $schema
     * ]
     *
     * @return bool
     */
    public function addToSchema(string $hookName, array $args): bool
    {
        $schema = &$args[0];

        $settings = [
            $this->_getDepositBatchIdSettingName(),
            $this->_getFailedMsgSettingName(),
            $this->_getSuccessMsgSettingName(),
        ];

        foreach ($settings as $settingName) {
            $schema->properties->{$settingName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function exportSubmissions(array $submissions, Context $context): array
    {
        // Get filter and set objectsFileNamePart (see: PubObjectsExportPlugin::prepareAndExportPubObjects)
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $xmlErrors = [];

        $temporaryFileId =  $exportPlugin->exportAsDownload($context, $submissions, $filterName, 'preprints', null, $xmlErrors);
        return ['temporaryFileId' => $temporaryFileId, 'xmlErrors' => $xmlErrors];
    }

    /**
     * @inheritDoc
     */
    public function depositSubmissions(array $submissions, Context $context): array
    {
        $exportPlugin = $this->_getExportPlugin();
        $filterName = $exportPlugin->getSubmissionFilter();
        $responseMessage = '';
        $status = $exportPlugin->exportAndDeposit($context, $submissions, $filterName, 'articles', $responseMessage);

        return [
            'hasErrors' => !$status,
            'responseMessage' => $responseMessage
        ];
    }

    /**
     * Includes plugin in list of configurable registration agencies for DOI depositing functionality
     *
     * @param $hookName string DoiSettingsForm::setEnabledRegistrationAgencies
     * @param $args array [
     *      @option $enabledRegistrationAgencies array
     * ]
     */
    public function addAsRegistrationAgencyOption(string $hookName, array $args)
    {
        $enabledRegistrationAgencies = &$args[0];
        $enabledRegistrationAgencies[] = [
            'value' => $this->getName(),
            'label' => 'Crossref'
        ];
    }

    /**
     * Includes human-readable name of registration agency for display in conjunction with how/with whom the
     * DOI was registered.
     *
     * @param string $hookName DoiListPanel::setConfig
     * @param $args [
     *      @option $config array
     * ]
     */
    public function addRegistrationAgencyName(string $hookName, array $args): bool {
        $config = &$args[0];
        $config['registrationAgencyNames'][$this->_getExportPlugin()->getName()] = $this->getRegistrationAgencyName();

        return HOOK::CONTINUE;
    }

    /**
     * Checks if plugin meets registration agency-specific requirements for being active and handling deposits
     *
     * @param Context $context
     * @return bool
     */
    public function isPluginConfigured(Context $context): bool
    {
        $form = new classes\form\CrossRefSettingsForm($this->_getExportPlugin(), $context->getId());
        $configurationErrors = $this->_getConfigurationErrors($context, $form);

        if (!empty($configurationErrors)) {
            return false;
        }

        if (!in_array(Repo::doi()::TYPE_PUBLICATION, $context->getData(Context::SETTING_ENABLED_DOI_TYPES) ?? [])) {
            return false;
        }
        return true;
    }

    /**
     * Get configured registration agency display name for use in DOI management pages
     * @return string
     */
    public function getRegistrationAgencyName(): string
    {
        return __('plugins.generic.crossref.registrationAgency.name');
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessageKey(): ?string
    {
        return $this->_getFailedMsgSettingName();
    }

    /**
     * @inheritDoc
     */
    public function getRegisteredMessageKey(): ?string
    {
        return $this->_getSuccessMsgSettingName();
    }

    /**
     * Adds Crossref specific info to Repo::doi()->markRegistered()
     *
     * @param string $hookName Doi::markRegistered
     * @param array $args
     *
     * @return bool
     */
    public function editMarkRegisteredParams(string $hookName, array $args): bool
    {
        $editParams = &$args[0];
        $editParams[$this->_getFailedMsgSettingName()] = null;
        $editParams[$this->_getSuccessMsgSettingName()] = null;

        return false;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? array(
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ) : array(),
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {

            // Return a JSON response containing the
            // settings form
            case 'settings':
                $context = $request->getContext();

                $form = new classes\form\CrossRefSettingsForm($this->_getExportPlugin(), $context->getId());
                $form->initData();

                // Check for configuration errors
                $configurationErrors = $this->_getConfigurationErrors($context, $form);

                $templateMgr = \APP\template\TemplateManager::getManager($request);
                $templateMgr->assign(
                    [
                        'configurationErrors' => $configurationErrors
                    ]
                );

                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    private function _getExportPlugin()
    {
        if (empty($this->_exportPlugin)) {
            $pluginCategory = 'importexport';
            $pluginPathName = 'CrossRefExportPlugin';
            $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            // If being run from CLI, there is no context, so plugin initialization would not have been fired
            if ($this->_exportPlugin === null && !isset($_SERVER['SERVER_NAME'])) {
                $this->_pluginInitialization();
                $this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
            }
        }
        return $this->_exportPlugin;
    }

    /**
     * @return int[]
     */
    private function _getConfigurationErrors(Context $context, classes\form\CrossRefSettingsForm $form = null): array
    {
        $configurationErrors = [];

        foreach ($form->getFormFields() as $fieldName => $fieldType) {
            if ($form->isOptional($fieldName)) {
                continue;
            }
            $pluginSetting = $this->_getExportPlugin()->getSetting($context->getId(), $fieldName);
            if (empty($pluginSetting)) {
                $configurationErrors[] = EXPORT_CONFIG_ERROR_SETTINGS;
                break;
            }
        }
        $doiPrefix = $context->getData(Context::SETTING_DOI_PREFIX);
        if (empty($doiPrefix)) {
            $configurationErrors[] = DOI_EXPORT_CONFIG_ERROR_DOIPREFIX;
        }

        return $configurationErrors;
    }

    /**
     * Get request failed message setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::failedMsg)
     *
     * @return string
     */
    private function _getFailedMsgSettingName(): string
    {
        return $this->getName() . '_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * NB: Change from 3.3.x to camelCase (over crossref::batchId)
     *
     * @return string
     */
    private function _getDepositBatchIdSettingName(): string
    {
        return $this->getName() . '_batchId';
    }

    private function _getSuccessMsgSettingName(): string
    {
        return $this->getName() . '_successMsg';
    }
}
