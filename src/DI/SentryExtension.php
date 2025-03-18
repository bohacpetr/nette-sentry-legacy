<?php

declare(strict_types=1);

namespace bohyn\NetteSentryLegacy\DI;

use Nette\DI\CompilerExtension;
use Nette\PhpGenerator\ClassType;
use Tracy\Debugger;
use Tracy\Logger;

class SentryExtension extends CompilerExtension
{
	/**
	 * @var bool
	 */
	private $enabled = false;

	public function loadConfiguration()
	{
		$config = $this->getConfig();
		if (!$config['dsn']) {
			Debugger::log('Unable to initialize SentryExtension, dsn config option is missing', Logger::WARNING);
			return;
		}
		$this->enabled = true;

		$logger = $this->getContainerBuilder()
			->addDefinition($this->prefix('logger'))
			->setFactory(SentryLogger::class, [Debugger::$logDirectory]);

		// configure logger before registering the Sentry SDK

		$logger->addSetup('setUserFields', [$config['user_fields'] ?? []]);
		$logger->addSetup('setSessionSections', [$config['session_sections'] ?? []]);
		$logger->addSetup('setPriorityMapping', [$config['priority_mapping'] ?? []]);
		$logger->addSetup('setTracesSampleRate', [$config['traces_sample_rate'] ?? []]);

		// register Sentry SDK

		$logger->addSetup('register', [
			$config['dsn'],
			$config['environment'],
		]);
	}

	public function beforeCompile()
	{
		if (!$this->enabled) {
			return;
		}

		$builder = $this->getContainerBuilder();
		if ($builder->hasDefinition('tracy.logger')) {
			$builder->getDefinition('tracy.logger')->setAutowired(false);
		}
		if ($builder->hasDefinition('security.user')) {
			$builder->getDefinition($this->prefix('logger'))
				->addSetup('setUser', [$builder->getDefinition('security.user')]);
		}
		if ($builder->hasDefinition('session.session')) {
			$builder->getDefinition($this->prefix('logger'))
				->addSetup('setSession', [$builder->getDefinition('session.session')]);
		}
	}

	public function afterCompile(ClassType $class)
	{
		if (!$this->enabled) {
			return;
		}

		$class->getMethods()['initialize']
			->addBody('Tracy\Debugger::setLogger($this->getService(?));', [ $this->prefix('logger') ]);
	}
}
