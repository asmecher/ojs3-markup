<?php

/**
 * @file plugins/generic/markup/MarkupBatchGatewayPlugin.inc.php
 *
 * Copyright (c) 2003-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MarkupBatchGatewayPlugin
 * @ingroup plugins_generic_markup
 *
 * @brief Responds to requests for batch files conversion.
 * 
 */

import('lib.pkp.classes.plugins.GatewayPlugin');

class MarkupBatchGatewayPlugin extends GatewayPlugin {
	/** @var $parentPluginName string Name of parent plugin */
	protected $_parentPluginName = null;
	/** @var $helper MarkupConversionHelper Markup conversion helper object */
	protected $_markupConversionHelper = null;
	/** @var $helper MarkupBatchConversionHelper Batch conversion helper object */
	protected $_batchConversionHelper = null;
	/** @var $user User user object */
	protected $_user = null;
	/** @var $plugin MarkupPlugin Reference to markup plugin */
	protected $_plugin = null;
	/** @var $otsWrapper XMLPSWrapper Reference to wrapper class for OTS Service */
	protected $_otsWrapper = null;

	/**
	 * Constructor
	 * @param string $parentPluginName
	 */
	public function __construct($parentPluginName) {
		parent::__construct();
		$this->_parentPluginName = $parentPluginName;
		$this->_plugin = PluginRegistry::getPlugin('generic', $parentPluginName);

		// initialize batch conversion helper
		$this->import('classes.MarkupBatchConversionHelper');
		$this->_batchConversionHelper = new MarkupBatchConversionHelper();
	}

	/**
	 * Creates an instance of markup conversion helper
	 * @param $request PKPRequest
	 * @param $journal Journal
	 */
	protected function initMarkupConversionHelper($request, $journal) {
		$this->import('classes.MarkupConversionHelper');
		$this->_otsWrapper = MarkupConversionHelper::getOTSWrapperInstance(
			$this->_plugin,
			$journal,
			$this->_user
		);
		$this->_markupConversionHelper = new MarkupConversionHelper(
			$this->_plugin, 
			$this->_otsWrapper, 
			$this->_user
		);
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 *
	 * @return string Name of plugin
	 */
	public function getName() {
		return 'MarkupBatchGatewayPlugin';
	}

	/**
	 * Get plugin display name
	 *
	 * @return string Plugin display name
	 */
	public function getDisplayName() {
		return __('plugins.generic.markup.batch.displayName');
	}

	/**
	 * Get plugin description
	 *
	 * @return string Plugin description
	 */
	public function getDescription() {
		return __('plugins.generic.markup.batch.description');
	}

	/**
	 * Get the parent plugin
	 *
	 * @return MarkupPlugin Markup plugin object
	 */
	public function &getMarkupPlugin() {
		return $this->_plugin;
	}

	/**
	 * Overwrite plugin path with parent's plugin path
	 *
	 * @return string Plugin path
	 */
	public function getPluginPath() {
		$plugin =& $this->getMarkupPlugin();
		return $plugin->getPluginPath();
	}

	/**
	 * @copydoc Plugin::getSeq()
	 * @return int
	 */
	public function getSeq() {
		return parent::getSeq() + 1;
	}

	/**
	 * Handles URL requests for sending commands to batch conversion process
	 *
	 * @param $args Array of url arguments
	 *
	 * @return void
	 */
	public function fetch($args, $request) {
		// set custom error handler 
		set_error_handler(array($this->_batchConversionHelper,'errorHandler'), E_ERROR );

		// skip if conversion is already running
		if ($this->_batchConversionHelper->isRunning()) {
			return;
		}

		// Parse keys and values from arguments
		$keys = array();
		$values = array();
		foreach ($args as $index => $arg) {
			if ($arg == 'true') $arg = true;
			if ($arg == 'false') $arg = false;
		
			if ($index % 2 == 0) {
				$keys[] = $arg;
			} else {
				$values[] = $arg;
			}
		}
		$args = array_combine($keys, $values);

		// find user object
		$userDao = DAORegistry::getDAO('UserDAO');
		$userId = isset($args['userId']) ? (int) $args['userId'] : false;
		if (!$userId) {
			fatalError(__('plugins.generic.markup.archive.noUserID'));
			exit;
		}
		$this->_user = $userDao->getById($userId);

		$journal = $request->getJournal();
		// initialize markup conversion helper
		$this->initMarkupConversionHelper($request, $journal);
		// get list of submissions
		if (empty($_POST)) {
			return;
		}

		// access key
		$accessKey = isset($args['accessKey']) ? $args['accessKey'] : null;
		if (empty($accessKey)) {
			fatalError(__('plugins.generic.markup.archive.noAccessKey'));
			exit;
		}

		// validate access key
		if (!$this->_user || !MarkupConversionHelper::validateAccessToken($this->_user, $accessKey)) {
			fatalError(__('plugins.generic.markup.archive.noAccessKey'));
			exit;
		}

		$submissions = $_POST;

		$pid = getmypid();
		$submissionCount = count($submissions);
		$data = array(
			'pid' 			=> $pid,
			'submissionCount' 	=> $submissionCount,
			'processedCount'	=> 0,
		);

		// create outfile
		$this->_batchConversionHelper->createOutFile($data);

		// find current user's group
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroups = $userGroupDao->getByUserId($this->_user->getId(), $journal->getId());
		$userGroup = $userGroups->next();

		// batch conversion
		$processedCount = 0;
		$tmpZipFile = null;
		$submissionDao = Application::getSubmissionDAO();
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$cancellationToken = sha1(time());
		foreach ($submissions as $submissionId => $submissionFileId) {
			$data = array(
				'pid' 			=> $pid,
				'cancellationToken'	=> $cancellationToken,
				'submissionCount' 	=> $submissionCount,
				'processedCount'	=> ++$processedCount,
				'submissionId'		=> $submissionId,
				'conversionStatus'	=> '',
				'otsJobId'		=> ''
			);

			// load submission file
			$submissionFile = $submissionFileDao->getLatestRevision($submissionFileId);
			if (!$submissionFile) {
				continue;
			}

			// load submission
			$submission = $submissionDao->getById($submissionFile->getSubmissionId());

			try {
				$jobInfoId = MarkupConversionHelper::createConversionJobInfo(
					$journal, 
					$this->_user, 
					$submissionFileId
				);
				$data['jobInfoId'] = $jobInfoId;
				$this->_batchConversionHelper->updateOutFile($data);

				$jobId = $this->_markupConversionHelper->triggerConversion(
					$request->getJournal(),
					$submissionFile,
					$submissionFile->getFileStage(),
					'galley-generate',
					$jobInfoId
				);
				$data['otsJobId'] = $jobId;

				// status callback closure
				$batchConversionHelper = $this->_batchConversionHelper;
				$user = $this->_user;
				$plugin = $this->_plugin;
				$statusCallbackFn = function($jobStatus) use ($data, $batchConversionHelper, $request, $user, $plugin) {
					$wrapper = MarkupConversionHelper::getOTSWrapperInstance(
						$plugin,
						$request->getJournal(),
						$user
					);
					$data['conversionStatus'] = $wrapper->statusCodeToLabel($jobStatus);
					$batchConversionHelper->updateOutFile($data);
				};

				$tmpZipFile = $this->_markupConversionHelper->retrieveConversionJobArchive(
					$submissionFile, 
					$jobId,
					$statusCallbackFn
				);
				if (($tmpZipFile == false) || !file_exists($tmpZipFile)) {
					throw new Exception(__('plugins.generic.markup.archive-download-failure'));
				}

				$extractionPath = null;
				if (($extractionPath = $this->_markupConversionHelper->unzipArchive($tmpZipFile)) === false) {
					throw new Exception(__('plugins.generic.markup.archive-extract-failure')); 
				}

				$fileName = "document" . '__' . date('Y-m-d_h:i:s');
				$this->_markupConversionHelper->handleArchiveExtractionAfterGalleyGenerate(
					$extractionPath,
					$journal,
					$submission,
					$submissionFile,
					$fileName
				);
			}
			catch (Exception $e) {
				error_log('EXCEPTION!!! ' . $e->getMessage());
				$statusCallbackFn($e->getMessage());
				// In case of exception pause few seconds so that user gets a chance to
				// see the error before we carry on
				sleep(5);
			}
		}

		$this->_batchConversionHelper->deleteOutFile();
	}
}
