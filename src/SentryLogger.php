<?php

declare(strict_types=1);

namespace bohyn\NetteSentryLegacy;

use Nette\Http\Session;
use Nette\Security\IIdentity;
use Nette\Security\User;
use Raven_Client;
use Throwable;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Logger;

class SentryLogger extends Logger
{
	/**
	 * @var Raven_Client
	 */
	private $client;

	/**
	 * @var User|null
	 */
	private $user = null;

	/**
	 * @var Session|null
	 */
	private $session = null;

	/**
	 * @var string
	 */
	private $dsn;

	/**
	 * @var string
	 */
	private $environment;

	/**
	 * @var array
	 */
	private $userFields = [];

	/**
	 * @var array
	 */
	private $sessionSections = [];

	/**
	 * @var array
	 */
	private $priorityMapping = [];

	/**
	 * @var float|null
	 */
	private $tracesSampleRate = null;

	public function __construct()
	{
	}


	public function register(string $dsn, string $environment)
	{
		$this->dsn = $dsn;
		$this->environment = $environment;
		$this->client = new Raven_Client($dsn);
		$this->email = & Debugger::$email;
		$this->directory = Debugger::$logDirectory;
	}

	public function getDsn(): string
	{
		return $this->dsn;
	}

	public function getEnvironment(): string
	{
		return $this->environment;
	}

	public function setUser(User $user)
	{
		$this->user = $user;
	}

	public function setUserFields(array $userFields)
	{
		$this->userFields = $userFields;
	}

	public function setSessionSections(array $sessionSections)
	{
		$this->sessionSections = $sessionSections;
	}

	public function setPriorityMapping(array $priorityMapping)
	{
		$this->priorityMapping = $priorityMapping;
	}

	public function setTracesSampleRate(float $tracesSampleRate)
	{
		$this->tracesSampleRate = $tracesSampleRate;
	}

	public function setSession(Session $session)
	{
		$this->session = $session;
	}

	/**
	 * @return IIdentity|null
	 */
	public function getIdentity()
	{
		return $this->user !== null && $this->user->isLoggedIn()
			? $this->user->getIdentity()
			: null;
	}

	/**
	 * @param mixed $message
	 * @param string $priority
	 * @return void
	 */
	public function log($message, $priority = Logger::INFO)
	{
		parent::log($message, $priority);
		$severity = $this->tracyPriorityToSentrySeverity($priority);

		// if it's non-default severity, let's try configurable mapping
		if (!$severity) {
			$mappedSeverity = $this->priorityMapping[$priority] ?? null;
			if ($mappedSeverity) {
				$severity = $mappedSeverity;
			}
		}
		// if we still don't have severity, don't log anything
		if (!$severity) {
			return;
		}

		if ($identity = $this->getIdentity()) {
			$this->client->user_context(['eid' => $identity->getId()]);
			$userFields = [];

			foreach ($this->userFields as $name) {
				$userFields[$name] = $this->getIdentity()->{$name} ?? null;
			}

			$this->client->user_context($userFields);
		}

		if ($this->session) {
			$data = [];

			foreach ($this->sessionSections as $section) {
				foreach ($this->session->getSection($section)->getIterator() as $key => $val) {
					$data[$section][$key] = $val;
				}
			}

			$this->client->extra_context($data);
		}

		if ($message instanceof Throwable) {
			$this->client->captureException($message);
		} else {
			$this->client->captureMessage(is_string($message) ? $message : Dumper::toText($message));
		}
	}

	/**
	 * @param string $priority
	 * @return string|null
	 */
	private function tracyPriorityToSentrySeverity(string $priority)
	{
		switch ($priority) {
			case Logger::DEBUG:
				return Raven_Client::DEBUG;
			case Logger::INFO:
				return Raven_Client::INFO;
			case Logger::WARNING:
				return Raven_Client::WARNING;
			case Logger::ERROR:
			case Logger::EXCEPTION:
				return Raven_Client::ERROR;
			case Logger::CRITICAL:
				return Raven_Client::FATAL;
			default:
				return null;
		}
	}
}
