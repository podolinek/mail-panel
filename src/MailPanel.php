<?php

/**
 * This file is part of the Nextras\MailPanel library.
 * @license    New BSD
 * @link       https://github.com/nextras/mail-panel
 */

namespace Nextras\MailPanel;

use Latte;
use Nette\Http;
use Nette\Mail\MimePart;
use Nette\Object;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\IBarPanel;


/**
 * Extension for Tracy bar which shows sent emails
 */
class MailPanel extends Object implements IBarPanel
{
	/** @const int */
	const DEFAULT_COUNT = 5;

	/** @var Http\Request */
	private $request;

	/** @var IPersistentMailer */
	private $mailer;

	/** @var int */
	private $messagesLimit;

	/** @var string|NULL */
	private $tempDir;

	/** @var Latte\Engine */
	private $latteEngine;


	/**
	 * @param string            $tempDir
	 * @param Http\Request      $request
	 * @param IPersistentMailer $mailer
	 * @param int               $messagesLimit
	 */
	public function __construct($tempDir, Http\Request $request, IPersistentMailer $mailer, $messagesLimit = self::DEFAULT_COUNT)
	{
		$this->tempDir = $tempDir;
		$this->request = $request;
		$this->mailer = $mailer;
		$this->messagesLimit = $messagesLimit;

		$this->tryHandleRequest();
	}


	/**
	 * Returns panel ID
	 * @return string
	 */
	public function getId()
	{
		return __CLASS__;
	}


	/**
	 * Renders HTML code for custom tab
	 * @return string
	 */
	public function getTab()
	{
		$count = $this->mailer->getMessageCount();
		$label = $count . ' sent email' . ($count === 1 ? '' : 's');

		return '<span title="Mail Panel">' .
			'<svg viewBox="0 0 16 16">' .
  			'	<rect x="0" y="2" width="16" height="11" rx="1" ry="1" fill="#588ac8"/>' .
  			'	<rect x="1" y="3" width="14" height="9" fill="#eef3f8"/>' .
  			'	<rect x="2" y="4" width="12" height="7" fill="#dcebfe"/>' .
  			'	<path d="M 2 11 l 4 -4 q 2 -2 4 0 l 4 4" stroke="#bbccdd" fill="none"/>' .
  			'	<path d="M 2 4 l 4 4 q 2 2 4 0 l 4 -4" stroke="#85aae2" fill="#dee8f7"/>' .
			'</svg>' .
			'<span class="tracy-label">' . $label . '</span></span>';
	}


	/**
	 * @inheritdoc
	 */
	public function getPanel()
	{
		$latte = $this->getLatteEngine();

		return $latte->renderToString(__DIR__ . '/MailPanel.latte', array(
			'getLink' => array($this, 'getLink'),
			'messages' => $this->mailer->getMessages($this->messagesLimit),
		));
	}


	/**
	 * Run-time link helper
	 * @param  string $action
	 * @param  array  $params
	 * @return string
	 */
	public function getLink($action, array $params)
	{
		$url = $this->request->getUrl();
		$baseUrl = substr($url->getPath(), strrpos($url->getScriptPath(), '/') + 1);

		$params = array('action' => $action) + $params;
		$query = array();
		foreach ($params as $key => $value) {
			$query["nextras-mail-panel-$key"] = $value;
		}

		return $baseUrl . '?' . http_build_query($query);
	}


	/**
	 * @return Latte\Engine
	 */
	private function getLatteEngine()
	{
		if (!isset($this->latteEngine)) {
			$this->latteEngine = new Latte\Engine();
			$this->latteEngine->setTempDirectory($this->tempDir);
			$this->latteEngine->setAutoRefresh(FALSE);

			$this->latteEngine->onCompile[] = function (Latte\Engine $engine) {
				$set = new Latte\Macros\MacroSet($engine->getCompiler());
				$set->addMacro('link', 'echo %escape($getLink(%node.word, %node.array))');
			};

			$this->latteEngine->addFilter('attachmentLabel', function (MimePart $attachment) {
				$contentDisposition = $attachment->getHeader('Content-Disposition');
				$contentType = $attachment->getHeader('Content-Type');
				$matches  = Strings::match($contentDisposition, '#filename="(.+?)"#');
				return ($matches ? "$matches[1] " : '') . "($contentType)";
			});

			$this->latteEngine->addFilter('plainText', function (MimePart $part) {
				$ref = new \ReflectionProperty('Nette\Mail\MimePart', 'parts');
				$ref->setAccessible(TRUE);

				$queue = array($part);
				for ($i = 0; $i < count($queue); $i++) {
					/** @var MimePart $subPart */
					foreach ($ref->getValue($queue[$i]) as $subPart) {
						$contentType = $subPart->getHeader('Content-Type');
						if (Strings::startsWith($contentType, 'text/plain') && $subPart->getHeader('Content-Transfer-Encoding') !== 'base64') { // Take first available plain text
							return (string) $subPart->getBody();
						} elseif (Strings::startsWith($contentType, 'multipart/alternative')) {
							$queue[] = $subPart;
						}
					}
				}

				return $part->getBody();
			});
		}

		return $this->latteEngine;
	}


	/**
	 * @return void
	 */
	private function tryHandleRequest()
	{
		if (Debugger::$productionMode !== FALSE) {
			return;
		}

		$action = $this->request->getQuery('nextras-mail-panel-action');
		$messageId = $this->request->getQuery('nextras-mail-panel-message-id');
		$attachmentId = $this->request->getQuery('nextras-mail-panel-attachment-id');

		if ($action === 'detail' && is_string($messageId)) {
			$this->handleDetail($messageId);

		} elseif ($action === 'source' && is_string($messageId)) {
			$this->handleSource($messageId);

		} elseif ($action === 'attachment' && is_string($messageId) && ctype_digit($attachmentId)) {
			$this->handleAttachment($messageId, $attachmentId);

		} elseif ($action === 'delete-one' && is_string($messageId)) {
			$this->handleDeleteOne($messageId);

		} elseif ($action === 'delete-all') {
			$this->handleDeleteAll();
		}
	}


	/**
	 * @param  string $messageId
	 * @return void
	 */
	private function handleDetail($messageId)
	{
		$message = $this->mailer->getMessage($messageId);

		header('Content-Type: text/html');
		$latte = $this->getLatteEngine();
		$latte->render(__DIR__ . '/MailPanel.body.latte', array('message' => $message));
		exit;
	}


	/**
	 * @param  string $messageId
	 * @return void
	 */
	private function handleSource($messageId)
	{
		$message = $this->mailer->getMessage($messageId);

		header('Content-Type: text/plain');
		echo $message->getEncodedMessage();
		exit;
	}


	/**
	 * @param  string $messageId
	 * @param  int    $attachmentId
	 * @return void
	 */
	private function handleAttachment($messageId, $attachmentId)
	{
		$attachments = $this->mailer->getMessage($messageId)->getAttachments();
		if (!isset($attachments[$attachmentId])) {
			return;
		}

		$attachment = $attachments[$attachmentId];
		if (!$attachment->getHeader('Content-Type')) {
			return;
		}

		header('Content-Type: ' . $attachment->getHeader('Content-Type'));
		echo $attachment->getBody();
		exit;
	}


	/**
	 * @param  int $id
	 * @return void
	 */
	private function handleDeleteOne($id)
	{
		$this->mailer->deleteOne($id);
		$this->returnBack();
	}


	/**
	 * @return void
	 */
	private function handleDeleteAll()
	{
		$this->mailer->deleteAll();
		$this->returnBack();
	}


	/**
	 * @return void
	 */
	private function returnBack()
	{
		$url = $this->request->getReferer();
		if ($url === NULL) {
			throw new \RuntimeException('Unable to redirect back because your browser did not send referrer');
		}

		header('Location: ' . $url);
		exit;
	}
}
