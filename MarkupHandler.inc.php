<?php

/**
 * @file plugins/generic/markup/MarkupHandler.inc.php
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MarkupHandler
 * @ingroup plugins_generic_markup
 *
 * @brief Handle requests for markup plugin
 */

import('classes.handler.Handler');

class MarkupHandler extends Handler {
	/** @var MarkupPlugin The Document markup plugin */
	protected $_plugin;
	
	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct();	
		
		// set reference to markup plugin
		$this->_plugin = PluginRegistry::getPlugin('generic', 'markupplugin'); 
		
		$this->addRoleAssignment(
			array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT, ROLE_ID_REVIEWER, ROLE_ID_AUTHOR),
			array('convertToXml', 'generateGalleyFiles', 'profile', 
					'triggerConversion', 'fetchConversionJobStatus')
		);

		$this->addRoleAssignment(array(ROLE_ID_MANAGER), array('batch'));
	}
	
	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$op = $request->getRouter()->getRequestedOp($request);
		
		if(in_array($op, array('profile','batch'))) {
			import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
			$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		}
		else {
			import('lib.pkp.classes.security.authorization.SubmissionFileAccessPolicy');
			$this->addPolicy(new SubmissionFileAccessPolicy($request, $args, $roleAssignments, SUBMISSION_FILE_ACCESS_READ));
		}

		return parent::authorize($request, $args, $roleAssignments);
	}
	
	/**
	 * Triggers a job on xml server to convert a dox/pdf document to xml
	 * 
	 * @param $args array
	 * @param $request PKPRequest
	 * 
	 * @return JSONMessage
	 */
	public function convertToXml($args, $request) {
		$params = array (
			'messageKey' => 'plugins.generic.markup.modal.xmlConversionText',
			'target' => 'xml-conversion',
			'stageId' => $request->getUserVar('stageId')
		);
		return $this->_conversion($args, $request, $params);
	}
	
	/**
	 * Triggers a job on xml server to generate galley files
	 * 
	 * @param $args array
	 * @param $request PKPRequest
	 * 
	 * @return JSONMessage
	 */
	public function generateGalleyFiles($args, $request) {
		$params = array (
			'messageKey' => 'plugins.generic.markup.modal.galleyProductionText',
			'target' => 'galley-generate',
			'stageId' => WORKFLOW_STAGE_ID_PRODUCTION
		);
		return $this->_conversion($args, $request, $params);
	}
	
	/**
	 * 
	 * @param $args array
	 * @param $request PKPRequest
	 * @param $params array 
	 * @return JSONMessage
	 */
	protected function _conversion($args, $request, $params) {
		$context = $request->getContext();
		$dispatcher = $request->getDispatcher();
		$authType = $this->_plugin->getSetting($context->getId(), 'authType');
		
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		if (!$submissionFile) {
			return new JSONMessage(false);
		}
		
		$fileId = $submissionFile->getFileId();
		$stageId = $params['stageId'];
		
		$pluginIsConfigured = false;
		$this->_plugin->import('classes.MarkupConversionHelper');
		$configCreds = MarkupConversionHelper::readCredentialsFromConfig();
		if (MarkupConversionHelper::canUseCredentialsFromConfig($configCreds)) {
			$pluginIsConfigured = true;
		}
		else {
			$pluginIsConfigured = is_null($authType) ? false : true;
		}
		$loginCredentialsConfigured = true;
		
		// Import host, user and password variables into the current symbol table from an array
		extract($this->_plugin->getOTSLoginParametersForJournal($context->getId(), $request->getUser()));
		if (is_null($user) || is_null($password)) {
			$loginCredentialsConfigured = false;
		}
		
		$conversionTriggerUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'markup', 'triggerConversion', null, array('submissionId' => $submissionFile->getSubmissionId(), 'fileId' => $fileId, 'stageId' => $stageId, 'target' => $params['target']));
		$editorTemplateFile = $this->_plugin->getTemplatePath() . 'convert.tpl';
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('fileId', $fileId);
		$templateMgr->assign('messageKey', $params['messageKey']);
		$templateMgr->assign('pluginIsConfigured', $pluginIsConfigured);
		$templateMgr->assign('loginCredentialsConfigured', $loginCredentialsConfigured);
		$templateMgr->assign('conversionTriggerUrl', $conversionTriggerUrl);
		$templateMgr->assign('pluginJavaScriptURL', $this->_plugin->getJsUrl($request));
		$output = $templateMgr->fetch($editorTemplateFile);
		return new JSONMessage(true, $output);
	}
	
	/**
	 * Trigger a job on xml server
	 * 
	 * @param $args array
	 * @param $request PKPRequest
	 * 
	 * @return JSONMessage
	 */
	public function triggerConversion($args, $request) {
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		if (!$submissionFile) {
			return new JSONMessage(false);
		}
		
		$fileId = $submissionFile->getFileId();
		$stageId = $request->getUserVar('stageId');
			
		$target = $request->getUserVar('target');
		$jobId = $this->_plugin->fetchGateway($fileId, $stageId, $target);
		
		$journal = $request->getJournal();
		$url = $this->_plugin->getSetting($journal->getid(), 'markupHostURL');
		$message = __('plugins.generic.markup.job.success', array('url' => $url));
		
		$router = $request->getRouter();
		$dispatcher = $router->getDispatcher();
		$conversionJobStatusCheckUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'markup', 
			'fetchConversionJobStatus', null, array('submissionId' => $submissionFile->getSubmissionId(), 'fileId' => $submissionFile->getFileId(), 'stageId' => $stageId, 'job' => $jobId));
		$templateFile = $this->_plugin->getTemplatePath() . 'convert-result.tpl';
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('jobId', $jobId);
		$templateMgr->assign('message', $message);
		$templateMgr->assign('conversionJobStatusCheckUrl', $conversionJobStatusCheckUrl);
		$output = $templateMgr->fetch($templateFile);
		return new JSONMessage(true, $output);
	}
	
	/**
	 * fetch xml job status
	 * 
	 * @param $args array
	 * @param $request PKPRequest
	 * 
	 * @return JSONMessage
	 */
	public function fetchConversionJobStatus($args, $request)
	{
		$journal = $request->getJournal();
		$jobId = $request->getUserVar('job');	// TODO validate jobId here
		
		$markupJobInfoDao = DAORegistry::getDAO('MarkupJobInfoDAO');
		$jobInfo = $markupJobInfoDao->getMarkupJobInfo($jobId);
		$xmlJobId = $jobInfo->getXmlJobId();
		
		// Import host, user and password variables into the current symbol table from an array
		extract($this->_plugin->getOTSLoginParametersForJournal($journal->getId(), $request->getUser()));
		
		$this->_plugin->import('classes.XMLPSWrapper');
		$xmlpsWrapper = new XMLPSWrapper($host, $user, $password);
		$code = (int) $xmlpsWrapper->getJobStatus($xmlJobId);
		$status = $xmlpsWrapper->statusCodeToLabel($code);
		
		$isCompleted = in_array($code, array(XMLPSWrapper::JOB_STATUS_FAILED, XMLPSWrapper::JOB_STATUS_COMPLETED));
		$json = new JSONMessage(true, array(
				'status' => $status, 
				'isCompleted' => $isCompleted 
			)
		);
		
		if ($isCompleted) {
			$json->setEvent('dataChanged');
			$json->setAdditionalAttributes(array('reloadContainer' => true));
		}
		
		return $json;
	}
	
	/**
	 * Display login credentials form in case of user specific authentication
	 * @param $args array
	 * @param $request PKPRequest
	 * 
	 * @return JSONMessage
	 */
	public function profile($args, $request) {
		$context = $request->getContext();
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
		$templateMgr = TemplateManager::getManager($request);
		
		$this->_plugin->import('MarkupProfileSettingsForm');
		$form = new MarkupProfileSettingsForm($this->_plugin, $context->getId());
		if ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				$notificationManager = new NotificationManager();
				$notificationManager->createTrivialNotification(
						$request->getUser()->getId(),
						NOTIFICATION_TYPE_SUCCESS,
						array('contents' => __('plugins.generic.markup.settings.saved'))
						);
				return new JSONMessage(true);
			}
		} else {
			$form->initData();
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * Display batch conversion page
	 * @param $args array
	 * @param $request PKPRequest
	 *
	 * @return JSONMessage
	 */
	public function batch($args, $request) {
		AppLocale::requireComponents(
			LOCALE_COMPONENT_APP_SUBMISSION,
			LOCALE_COMPONENT_PKP_SUBMISSION,
			LOCALE_COMPONENT_APP_EDITOR,
			LOCALE_COMPONENT_PKP_EDITOR,
			LOCALE_COMPONENT_PKP_COMMON,
			LOCALE_COMPONENT_APP_COMMON
		);

		$this->_plugin->import('classes.MarkupBatchConversionHelper');
		$batchConversionHelper = new MarkupBatchConversionHelper();

		$context = $request->getContext();
		$dispatcher = $request->getDispatcher();
		$templateMgr = TemplateManager::getManager();
		$submissionMetadata = $batchConversionHelper->buildSubmissionMetadataByContext($context->getId());
		$batchConversionStatusUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'batch', 'fetchConversionStatus', null);
		$startConversionUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'batch', 'startConversion', null);
		$templateMgr->assign('batchConversionStatusUrl', $batchConversionStatusUrl);
		$templateMgr->assign('startConversionUrl', $startConversionUrl);

		if ($batchConversionHelper->isRunning()) {
			$data = $batchConversionHelper->readOutFile();
			$cancelConversionUrl = $dispatcher->url($request, ROUTE_PAGE, null, 'batch', 'cancelConversion',
					null, array('token' => $data['cancellationToken']));
			$templateMgr->assign('cancelConversionUrl', $cancelConversionUrl);
		}
		$templateMgr->assign('submissions', $submissionMetadata);
		$templateMgr->assign('batchConversionIsRunning', $batchConversionHelper->isRunning());
		$templateFile = $this->_plugin->getTemplatePath() . 'batchConversion.tpl';
		$output = $templateMgr->fetch($templateFile);
		return new JSONMessage(true, $output);
	}

	/**
	 * display images attached to XML document
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 *
	 * @return void
	 */
	public function media($args, $request) {
		$user = $request->getUser();
		$context = $request->getContext();
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		if (!$submissionFile) {
			fatalError('Invalid request');
		}

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		import('lib.pkp.classes.submission.SubmissionFile'); // Constants
		$dependentFiles = $submissionFileDao->getLatestRevisionsByAssocId(
			ASSOC_TYPE_SUBMISSION_FILE, 
			$submissionFile->getFileId(), 
			$submissionFile->getSubmissionId(), 
			SUBMISSION_FILE_DEPENDENT
		);

		// make sure this is an xml document
		if (!in_array($submissionFile->getFileType(), array('text/xml', 'application/xml'))) {
			fatalError('Invalid request');
		}

		$mediaSubmissionFile = null;
		foreach ($dependentFiles as $dependentFile) {
			if ($dependentFile->getOriginalFileName() == $request->getUserVar('fileName')) {
				$mediaSubmissionFile = $dependentFile;
				break;
			}
		}

		if (!$mediaSubmissionFile) {
			$request->getDispatcher()->handle404();
		}

		$filePath = $mediaSubmissionFile->getFilePath();
		header('Content-Type:'.$mediaSubmissionFile->getFileType());
		header('Content-Length: ' . $mediaSubmissionFile->getFileSize());
		readfile($filePath);
	}
}
