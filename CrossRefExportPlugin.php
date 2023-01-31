<?php

/**
 * @file plugins/generic/crossref/CrossRefExportPlugin.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class CrossRefExportPlugin
 * @brief CrossRef/MEDLINE XML metadata export plugin
 */

namespace APP\plugins\generic\crossref;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\DOIPubIdExportPlugin;
use GuzzleHttp\Exception\GuzzleException;
use PKP\config\Config;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\plugins\HookRegistry;
use PKP\plugins\PluginRegistry;

// The status of the Crossref DOI.
// any, notDeposited, and markedRegistered are reserved
define('CROSSREF_STATUS_FAILED', 'failed');

define('CROSSREF_API_DEPOSIT_OK', 200);
define('CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF', 403);

define('CROSSREF_API_URL', 'https://api.crossref.org/v2/deposits');
//TESTING
define('CROSSREF_API_URL_DEV', 'https://test.crossref.org/v2/deposits');

define('CROSSREF_API_STATUS_URL', 'https://doi.crossref.org/servlet/submissionDownload');
//TESTING
define('CROSSREF_API_STATUS_URL_DEV', 'https://test.crossref.org/servlet/submissionDownload');

// The name of the setting used to save the registered DOI and the URL with the deposit status.
define('CROSSREF_DEPOSIT_STATUS', 'depositStatus');

class CrossRefExportPlugin extends DOIPubIdExportPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            // register hooks. This will prevent DB access attempts before the
            // schema is installed.
            if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
                return true;
            }
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName()
    {
        return 'CrossRefExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.importexport.crossref.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.importexport.crossref.description');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSubmissionFilter()
     */
    public function getSubmissionFilter()
    {
        return 'preprint=>crossref-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getStatusMessage()
     */
    public function getStatusMessage($request)
    {
        // if the failure occured on request and the message was saved
        // return that message
        $submissionId = $request->getUserVar('submissionId');
        $preprint = Repo::submission()->get($submissionId);
        $failedMsg = $preprint->getData('doiObject')->getData($this->getFailedMsgSettingName());
        if (!empty($failedMsg)) {
            return $failedMsg;
        }

        $context = $request->getContext();

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? CROSSREF_API_STATUS_URL_DEV : CROSSREF_API_STATUS_URL,
                [
                    'form_params' => [
                        'doi_batch_id' => $request->getUserVar('batchId'),
                        'type' => 'result',
                        'usr' => $this->getSetting($context->getId(), 'username'),
                        'pwd' => $this->getSetting($context->getId(), 'password'),
                    ]
                ]
            );
        } catch (GuzzleHttp\Exception\RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $returnMessage = $e->getResponse()->getBody(true) . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')';
            }
            return __('plugins.importexport.common.register.error.mdsError', ['param' => $returnMessage]);
        }

        return (string) $response->getBody();
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings()
    {
        return array_merge(parent::_getObjectAdditionalSettings(), [
            $this->getDepositBatchIdSettingName(),
            $this->getFailedMsgSettingName(),
        ]);
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix()
    {
        return 'crossrefplugin';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName()
    {
        return (string) \APP\plugins\generic\crossref\classes\form\CrossRefSettingsForm::class;
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName()
    {
        return (string) CrossrefExportDeployment::class;
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     *
     * @param null|mixed $noValidation
     */
    public function exportAndDeposit($context, $objects, $filter, $objectsFileNamePart, string &$responseMessage, $noValidation = null)
    {
        $fileManager = new FileManager();
        $resultErrors = [];

        assert($filter != null);
        // Errors occured will be accessible via the status link
        // thus do not display all errors notifications (for every preprint),
        // just one general.
        // Warnings occured when the registration was successfull will however be
        // displayed for each preprint.
        $errorsOccured = false;
        // The new Crossref deposit API expects one request per object.
        // On contrary the export supports bulk/batch object export, thus
        // also the filter expects an array of objects.
        // Thus the foreach loop, but every object will be in an one item array for
        // the export and filter to work.
        foreach ($objects as $object) {
            // Get the XML
            // Supply an exportErrors array because otherwise exportXML() will echo out export errors
            $exportErrors = [];
            $exportXml = $this->exportXML([$object], $filter, $context, $noValidation, $exportErrors);
            // Write the XML to a file.
            // export file name example: crossref-20160723-160036-preprints-1-1.xml
            $objectsFileNamePart = $objectsFileNamePart . '-' . $object->getId();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $this->depositXML($object, $context, $exportFileName);
            if (!$result) {
                $errorsOccured = true;
            }
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
        }
        // Prepare response message and return status
        if (empty($resultErrors)) {
            if ($errorsOccured) {
                $responseMessage = 'plugins.importexport.crossref.register.error.mdsError';
                return false;
            } else {
                $responseMessage = $this->getDepositSuccessNotificationMessageKey();
                return true;
            }
        } else {
            $responseMessage = 'api.dois.400.depositFailed';
            return false;
        }
    }

    /**
     * Exports and stores XML as a TemporaryFile
     *
     * @param \PKP\context\Context $context
     * @param array $objects
     * @param string $filter
     * @param string $objectsFileNamePart
     * @param bool|null $noValidation
     * @param array|null $exportErrors
     *
     * @return int|null
     * @throws Exception
     */
    public function exportAsDownload(\PKP\context\Context $context, array $objects, string $filter, string $objectsFileNamePart, ?bool $noValidation = null, ?array &$exportErrors = null): ?int
    {
        $fileManager = new TemporaryFileManager();

        $exportErrors = [];
        $exportXml = $this->exportXML($objects, $filter, $context, $noValidation, $exportErrors);

        $objectsFileNamePart = $objectsFileNamePart . '-' . $objects[0]->getId();
        $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');

        $fileManager->writeFile($exportFileName, $exportXml);

        $user = Application::get()->getRequest()->getUser();

        return $fileManager->createTempFileFromExisting($exportFileName, $user->getId());
    }

    /**
     * Deposit DOIs on publish
     *
     * @param $hookName string
     * @param $args array [
     *        @option Publication The new version of the publication
     *        @option Publication The old version of the publication
     * ]
     */
    public function depositOnPublish($hookName, $args)
    {
        error_log('depositOnPublish');
        $newPublication = $args[0];
        $objects[] = Repo::submission()->get($newPublication->getData('submissionId'));
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $filter = $this->getSubmissionFilter();
        $objectsFileNamePart = 'preprints';
        $noValidation = null;

        $fileManager = new FileManager();
        $resultErrors = [];
        $errorsOccured = false;

        foreach ($objects as $object) {
            $exportXml = $this->exportXML([$object], $filter, $context, $noValidation);
            $objectsFileNamePart = $objectsFileNamePart . '-' . $object->getId();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            $result = $this->depositXML($object, $context, $exportFileName);
            if (!$result) {
                $errorsOccured = true;
            }
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            $fileManager->deleteByPath($exportFileName);
        }
        // send notifications
        if (empty($resultErrors)) {
            if ($errorsOccured) {
                $this->_sendNotification(
                    $request->getUser(),
                    'plugins.importexport.crossref.register.error.mdsError',
                    NOTIFICATION_TYPE_ERROR
                );
            } else {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    NOTIFICATION_TYPE_SUCCESS
                );
            }
        } else {
            foreach ($resultErrors as $errors) {
                foreach ($errors as $error) {
                    assert(is_array($error) && count($error) >= 1);
                    $this->_sendNotification(
                        $request->getUser(),
                        $error[0],
                        NOTIFICATION_TYPE_ERROR,
                        $error[1] ?? null
                    );
                }
            }
        }
    }

    /**
     * @see PubObjectsExportPlugin::depositXML()
     *
     * @param array $objects
     * @param \PKP\context\Context $context
     * @param string $filename Export XML filename
     */
    public function depositXML($objects, $context, $filename)
    {
        $status = null;
        $msgSave = null;

        $httpClient = Application::get()->getHttpClient();
        assert(is_readable($filename));
        $response = null;

        try {
            $response = $httpClient->request(
                'POST',
                $this->isTestMode($context) ? CROSSREF_API_URL_DEV : CROSSREF_API_URL,
                [
                    'multipart' => [
                        [
                            'name' => 'usr',
                            'contents' => $this->getSetting($context->getId(), 'username'),
                        ],
                        [
                            'name' => 'pwd',
                            'contents' => $this->getSetting($context->getId(), 'password'),
                        ],
                        [
                            'name' => 'operation',
                            'contents' => 'doMDUpload',
                        ],
                        [
                            'name' => 'mdFile',
                            'contents' => fopen($filename, 'r'),
                        ],
                    ]
                ]
            );
        } catch (GuzzleException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $eResponseBody = $e->getResponse()->getBody(true);
                $eStatusCode = $e->getResponse()->getStatusCode();
                if ($eStatusCode == CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF) {
                    $xmlDoc = new DOMDocument();
                    $xmlDoc->loadXML($eResponseBody);
                    $batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
                    $msg = $xmlDoc->getElementsByTagName('msg')->item(0)->nodeValue;
                    $msgSave = $msg . PHP_EOL . $eResponseBody;
                    $status = Doi::STATUS_ERROR;
                    $this->updateDepositStatus($context, $objects, $status, $batchIdNode->nodeValue, $msgSave);
                    $returnMessage = $msg . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                } else {
                    $returnMessage = $eResponseBody . ' (' . $eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
                    $this->updateDepositStatus($context, $objects, Doi::STATUS_ERROR, null, $returnMessage);
                }
            }
            return [['plugins.importexport.common.register.error.mdsError', $returnMessage]];
        }

        // Get DOMDocument from the response XML string
        $xmlDoc = new DOMDocument();
        $xmlDoc->loadXML($response->getBody());
        $batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);

        // Get the DOI deposit status
        // If the deposit failed
        $failureCountNode = $xmlDoc->getElementsByTagName('failure_count')->item(0);
        $failureCount = (int) $failureCountNode->nodeValue;
        if ($failureCount > 0) {
            $status = Doi::STATUS_ERROR;
            $result = false;
        } else {
            // Deposit was received
            $status = Doi::STATUS_REGISTERED;
            $result = true;

            // If there were some warnings, display them
            $warningCountNode = $xmlDoc->getElementsByTagName('warning_count')->item(0);
            $warningCount = (int) $warningCountNode->nodeValue;
            if ($warningCount > 0) {
                $result = [['plugins.importexport.crossref.register.success.warning', htmlspecialchars($response->getBody())]];
            }
            // A possibility for other plugins (e.g. reference linking) to work with the response
            HookRegistry::call('crossrefexportplugin::deposited', [$this, $response->getBody(), $objects]);
        }

        // Update the status
        if ($status) {
            $this->updateDepositStatus($context, $objects, $status, $batchIdNode->nodeValue, $msgSave);
        }

        return $result;
    }

    /**
     * Check the CrossRef APIs, if deposits and registration have been successful
     *
     * @param \PKP\context\Context $context
     * @param Object $object The object getting deposited
     * @param int $status One of Doi::STATUS_*
     * @param string $batchId
     * @param ?string $failedMsg (opitonal)
     */
    public function updateDepositStatus($context, $object, $status, $batchId = null, $failedMsg = null)
    {
        assert($object instanceof \APP\submission\Submission);
        $doiIds = Repo::doi()->getDoisForSubmission($object->getId());

        foreach ($doiIds as $doiId) {
            $doi = Repo::doi()->get($doiId);

            $editParams = [
                'status' => $status,
                // Sets a new failedMsg or resets to null for removal of previous message
                $this->getFailedMsgSettingName() => $failedMsg,
                $this->getDepositBatchIdSettingName() => $batchId
            ];

            if ($status === Doi::STATUS_REGISTERED) {
                $editParams['registrationAgency'] = $this->getName();
            }

            Repo::doi()->edit($doi, $editParams);
        }
    }

    /**
     * @copydoc DOIPubIdExportPlugin::markRegistered()
     */
    public function markRegistered($context, $objects)
    {
        foreach ($objects as $object) {
            // Get all DOIs for each object
            $doiIds = Repo::doi()->getDoisForSubmission($object->getId());

            foreach ($doiIds as $doiId) {
                Repo::doi()->markRegistered($doiId);
            }
        }
    }

    /**
     * Get request failed message setting name.
     * NB: Changed as of 3.4
     * @return string
     */
    public function getFailedMsgSettingName()
    {
        return $this->getPluginSettingsPrefix().'_failedMsg';
    }

    /**
     * Get deposit batch ID setting name.
     * NB: Changed as of 3.4
     * @return string
     */
    public function getDepositBatchIdSettingName()
    {
        return $this->getPluginSettingsPrefix().'_batchId';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.common.register.success';
    }

    /**
     * @copydoc PKPImportExportPlugin::executeCLI()
     */
    public function executeCLICommand($scriptName, $command, $context, $outputFile, $objects, $filter, $objectsFileNamePart)
    {
        switch ($command) {
            case 'export':
                PluginRegistry::loadCategory('generic', true, $context->getId());
                $exportXml = $this->exportXML($objects, $filter, $context);
                if ($outputFile) {
                    file_put_contents($outputFile, $exportXml);
                }
                break;
            case 'register':
                PluginRegistry::loadCategory('generic', true, $context->getId());
                $fileManager = new FileManager();
                $resultErrors = [];
                // Errors occured will be accessible via the status link
                // thus do not display all errors notifications (for every preprint),
                // just one general.
                // Warnings occured when the registration was successfull will however be
                // displayed for each preprint.
                $errorsOccured = false;
                // The new Crossref deposit API expects one request per object.
                // On contrary the export supports bulk/batch object export, thus
                // also the filter expects an array of objects.
                // Thus the foreach loop, but every object will be in an one item array for
                // the export and filter to work.
                foreach ($objects as $object) {
                    // Get the XML
                    $exportXml = $this->exportXML([$object], $filter, $context);
                    // Write the XML to a file.
                    // export file name example: crossref-20160723-160036-preprints-1-1.xml
                    $objectsFileNamePartId = $objectsFileNamePart . '-' . $object->getId();
                    $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePartId, $context, '.xml');
                    $fileManager->writeFile($exportFileName, $exportXml);
                    // Deposit the XML file.
                    $result = $this->depositXML($object, $context, $exportFileName);
                    if (!$result) {
                        $errorsOccured = true;
                    }
                    if (is_array($result)) {
                        $resultErrors[] = $result;
                    }
                    // Remove all temporary files.
                    $fileManager->deleteByPath($exportFileName);
                }
                // display deposit result status messages
                if (empty($resultErrors)) {
                    if ($errorsOccured) {
                        echo __('plugins.importexport.crossref.register.error.mdsError') . "\n";
                    } else {
                        echo __('plugins.importexport.common.register.success') . "\n";
                    }
                } else {
                    echo __('plugins.importexport.common.cliError') . "\n";
                    foreach ($resultErrors as $errors) {
                        foreach ($errors as $error) {
                            assert(is_array($error) && count($error) >= 1);
                            $errorMessage = __($error[0], ['param' => $error[1] ?? null]);
                            echo "*** ${errorMessage}\n";
                        }
                    }
                    echo "\n";
                    $this->usage($scriptName);
                }
                break;
        }
    }
}
