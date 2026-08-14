<?php declare(strict_types=1);

namespace Evolution;

use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Configuration\Api\IConfiguration;
use Evolution\Service\EvolutionAgentService;
use Evolution\Service\EvolutionConfiguration;
use Evolution\Service\EvolutionDatabaseInspector;
use Evolution\Service\EvolutionHealthService;
use Evolution\Service\EvolutionWorkspaceService;

final class EvolutionPlugin implements IPlugin {

	public function __construct(
		private readonly IContainer $container
	) {}

	public static function getName(): string {
		return 'evolutionplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(EvolutionConfiguration::class, fn($c) => new EvolutionConfiguration(
				$c->get(IConfiguration::class)
			), IContainer::SHARED)
			->set(EvolutionWorkspaceService::class, fn($c) => new EvolutionWorkspaceService(
				$c->get(EvolutionConfiguration::class),
				$c->get(IClassMap::class)
			), IContainer::SHARED)
			->set(EvolutionDatabaseInspector::class, fn($c) => new EvolutionDatabaseInspector(
				$c->get(IContainer::class)
			), IContainer::SHARED)
			->set(EvolutionHealthService::class, fn($c) => new EvolutionHealthService(
				$c->get(EvolutionConfiguration::class),
				$c->get(IContainer::class),
				$c->get(EvolutionWorkspaceService::class)
			), IContainer::SHARED)
			->set(EvolutionAgentService::class, fn($c) => new EvolutionAgentService(
				$c->get(EvolutionConfiguration::class),
				$c->get(IContainer::class),
				$c->get(EvolutionWorkspaceService::class),
				$c->get(EvolutionHealthService::class)
			), IContainer::SHARED);
	}
}
