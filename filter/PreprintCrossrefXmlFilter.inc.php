<?php

/**
 * @file plugins/generic/crossref/filter/PreprintCrossrefXmlFilter.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PreprintCrossrefXmlFilter
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts a Preprint to a Crossref XML document.
 */

use APP\core\Application;
use PKP\i18n\LocaleConversion;
use PKP\submission\PKPSubmission;

import('lib.pkp.plugins.importexport.native.filter.NativeExportFilter');

class PreprintCrossrefXmlFilter extends NativeExportFilter
{
    /**
     * Constructor
     *
     * @param $filterGroup FilterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML preprint export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from PersistableFilter
    //
    /**
     * @copydoc PersistableFilter::getClassName()
     */
    public function getClassName()
    {
        return 'plugins.generic.crossref.filter.PreprintCrossrefXmlFilter';
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     *
     * @param $pubObjects array Array of Issues or Submissions
     *
     * @return DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and appet the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        // Loop through all submissions
        foreach ($pubObjects as $pubObject) {
            // Always include the current publication
            $postedContentNode = $this->createPostedContentNode($doc, $pubObject->getCurrentPublication(), $pubObject);
            $bodyNode->appendChild($postedContentNode);
            // Loop through other publications in case other DOI's exist
            $publications = array_reverse($pubObject->getPublishedPublications());
            foreach ($publications as $publication) {
                if ($publication->getDoi() && $publication->getDoi() !== $pubObject->getCurrentPublication()->getDoi()) {
                    $postedContentNode = $this->createPostedContentNode($doc, $publication, $pubObject);
                    $bodyNode->appendChild($postedContentNode);
                }
            }
        }
        return $doc;
    }

    //
    // Submission conversion functions
    //

    /**
     * Create and return the root node 'doi_batch'.
     *
     * @param $doc DOMDocument
     *
     * @return DOMElement
     */
    public function createRootNode($doc)
    {
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', $deployment->getJATSNamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', $deployment->getAINamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:rel', $deployment->getRELNamespace());
        $rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create and return the head node 'head'.
     *
     * @param $doc DOMDocument
     *
     * @return DOMElement
     */
    public function createHeadNode($doc)
    {
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'head');
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi_batch_id', htmlspecialchars($context->getData('initials', $context->getPrimaryLocale()) . '_' . time(), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'timestamp', date('YmdHisv')));
        $depositorNode = $doc->createElementNS($deployment->getNamespace(), 'depositor');
        $depositorName = $plugin->getSetting($context->getId(), 'depositorName');
        if (empty($depositorName)) {
            $depositorName = $context->getData('supportName');
        }
        $depositorEmail = $plugin->getSetting($context->getId(), 'depositorEmail');
        if (empty($depositorEmail)) {
            $depositorEmail = $context->getData('supportEmail');
        }
        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'depositor_name', htmlspecialchars($depositorName, ENT_COMPAT, 'UTF-8')));
        $depositorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'email_address', htmlspecialchars($depositorEmail, ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($depositorNode);
        $publisherServer = $context->getLocalizedName();
        $headNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'registrant', htmlspecialchars($publisherServer, ENT_COMPAT, 'UTF-8')));
        return $headNode;
    }

    /**
     * Create and return the posted content node 'posted_content'.
     *
     * @param $doc DOMDocument
     *
     * @return DOMElement
     */
    public function createPostedContentNode($doc, $publication, $submission)
    {
        assert(is_a($publication, 'Publication'));
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $locale = $publication->getData('locale');

        $postedContentNode = $doc->createElementNS($deployment->getNamespace(), 'posted_content');
        $postedContentNode->setAttribute('type', 'preprint');

        // contributors
        $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');
        $authors = $publication->getData('authors');
        $isFirst = true;
        foreach ($authors as $author) {
            $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');
            $personNameNode->setAttribute('contributor_role', 'author');
            if ($isFirst) {
                $personNameNode->setAttribute('sequence', 'first');
            } else {
                $personNameNode->setAttribute('sequence', 'additional');
            }

            $familyNames = $author->getFamilyName(null);
            $givenNames = $author->getGivenName(null);

            // Check if both givenName and familyName is set for the submission language.
            if (isset($familyNames[$locale]) && isset($givenNames[$locale])) {
                $personNameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($locale));
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$locale]), ENT_COMPAT, 'UTF-8')));
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyNames[$locale]), ENT_COMPAT, 'UTF-8')));

                $hasAltName = false;
                foreach ($familyNames as $otherLocal => $familyName) {
                    if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                        if (!$hasAltName) {
                            $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                            $personNameNode->appendChild($altNameNode);

                            $hasAltName = true;
                        }

                        $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                        $nameNode->setAttribute('language', LocaleConversion::getIso1FromLocale($otherLocal));

                        $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($familyName), ENT_COMPAT, 'UTF-8')));
                        if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                            $nameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars(ucfirst($givenNames[$otherLocal]), ENT_COMPAT, 'UTF-8')));
                        }

                        $altNameNode->appendChild($nameNode);
                    }
                }
            } else {
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars(ucfirst($author->getFullName(false)), ENT_COMPAT, 'UTF-8')));
            }

            if ($author->getData('orcid')) {
                $personNameNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid')));
            }
            $contributorsNode->appendChild($personNameNode);
            $isFirst = false;
        }
        $postedContentNode->appendChild($contributorsNode);

        // Titles
        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars($publication->getData('title', $submission->getLocale()), ENT_COMPAT, 'UTF-8')));
        if ($subtitle = $publication->getData('subtitle', $submission->getLocale())) {
            $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle', htmlspecialchars($subtitle, ENT_COMPAT, 'UTF-8')));
        }
        $postedContentNode->appendChild($titlesNode);

        // Posted date
        $postedContentNode->appendChild($this->createPostedDateNode($doc, $publication->getData('datePublished')));

        // abstract
        if ($abstract = $publication->getData('abstract', $locale)) {
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->appendChild($node = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'utf-8')));
            $postedContentNode->appendChild($abstractNode);
        }

        // license
        if ($publication->getData('licenseUrl')) {
            $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
            $licenseNode->setAttribute('name', 'AccessIndicators');
            $licenseNode->appendChild($node = $doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($publication->getData('licenseUrl'), ENT_COMPAT, 'UTF-8')));
            $postedContentNode->appendChild($licenseNode);
        }

        // DOI relations: if this version has a vorDoi or different DOI than the current publication (ie. versions and DOI versioning exits), add a relation node
        $parentDoi = $submission->getCurrentPublication()->getDoi() && $submission->getCurrentPublication()->getDoi() != $publication->getDoi() ? $submission->getCurrentPublication()->getDoi() : '';
        $vorDoi = $publication->getData('vorDoi') ? $publication->getData('vorDoi') : '';

        if ($parentDoi || $vorDoi) {
            $relationsDataNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:program');
            $relationsDataNode->setAttribute('name', 'relations');
            if ($parentDoi) {
                $relationsDataNode->appendChild($this->createParentDoiNode($doc, $parentDoi));
            }
            if ($vorDoi) {
                $relationsDataNode->appendChild($this->createVorDoiNode($doc, $vorDoi));
            }
            $postedContentNode->appendChild($relationsDataNode);
        }

        // DOI data:  for the current publication use default landing page URL, for other versions use a version specific URL, see https://github.com/pkp/pkp-lib/issues/7222
        $dispatcher = $this->_getDispatcher($request);
        if ($submission->getCurrentPublication()->getId() === $publication->getId()) {
            $url = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'preprint', 'view', $submission->getBestId());
        } else {
            $url = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'preprint', 'view', [$submission->getBestId(), 'version', $publication->getId()], null, null, true);
        }
        $postedContentNode->appendChild($this->createDOIDataNode($doc, $publication->getDoi(), $url));

        return $postedContentNode;
    }

    /**
     * Create and return the posted date node 'posted_date'.
     *
     * @param $doc DOMDocument
     * @param $objectPostedDate string
     *
     * @return DOMElement
     */
    public function createPostedDateNode($doc, $objectPostedDate)
    {
        $deployment = $this->getDeployment();
        $postedDate = strtotime($objectPostedDate);
        $postedDateNode = $doc->createElementNS($deployment->getNamespace(), 'posted_date');
        if (date('m', $postedDate)) {
            $postedDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'month', date('m', $postedDate)));
        }
        if (date('d', $postedDate)) {
            $postedDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'day', date('d', $postedDate)));
        }
        $postedDateNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'year', date('Y', $postedDate)));
        return $postedDateNode;
    }

    /**
     * Create and return the DOI data node 'doi_data'.
     *
     * @param $doc DOMDocument
     * @param $doi string
     * @param $url string
     *
     * @return DOMElement
     */
    public function createDOIDataNode($doc, $doi, $url)
    {
        $deployment = $this->getDeployment();
        $doiDataNode = $doc->createElementNS($deployment->getNamespace(), 'doi_data');
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'doi', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        $doiDataNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'resource', htmlspecialchars($url, ENT_COMPAT, 'UTF-8')));
        return $doiDataNode;
    }

    /**
     * Create and return the parent DOI relation node.
     *
     * @param $doc DOMDocument
     * @param $parentDoi string
     *
     * @return DOMElement
     */
    public function createParentDoiNode($doc, $parentDoi)
    {
        $deployment = $this->getDeployment();
        $parentDoiNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:related_item');
        $intraWorkRelationNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:intra_work_relation',  htmlspecialchars($parentDoi, ENT_COMPAT, 'UTF-8'));
        $intraWorkRelationNode->setAttribute('relationship-type', 'isVersionOf');
        $intraWorkRelationNode->setAttribute('identifier-type', 'doi');
        $parentDoiNode->appendChild($intraWorkRelationNode);

        return $parentDoiNode;
    }

    /**
     * Create and return the VOR DOI relation node.
     *
     * @param $doc DOMDocument
     * @param $vorDoi string
     *
     * @return DOMElement
     */
    public function createVorDoiNode($doc, $vorDoi)
    {
        $deployment = $this->getDeployment();
        $vorDoiNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:related_item');
        $intraWorkRelationNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:intra_work_relation',  htmlspecialchars($vorDoi, ENT_COMPAT, 'UTF-8'));
        $intraWorkRelationNode->setAttribute('relationship-type', 'isPreprintOf');
        $intraWorkRelationNode->setAttribute('identifier-type', 'doi');
        $vorDoiNode->appendChild($intraWorkRelationNode);

        return $vorDoiNode;
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     *
     * @param \APP\core\Request $request
     *
     * @return \PKP\core\Dispatcher
     */
    protected function _getDispatcher(\APP\core\Request $request): \PKP\core\Dispatcher
    {
        $dispatcher = $request->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = \APP\core\Application::get()->getDispatcher();
        }

        return $dispatcher;
    }
}
