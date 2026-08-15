<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Configuration\Api\IConfiguration;

final class EvolutionConfiguration {

	public const WORKSPACE_PLUGIN = 'EvolutionWorkspace';
	public const WORKSPACE_PLUGIN_PATH = 'plugin/EvolutionWorkspace';

	public function __construct(
		private readonly IConfiguration $configuration
	) {}

	public function getWorkspace(): string {
		return trim($this->configuration->getString('evolution', 'workspace', ''));
	}

	public function getWorkspacePlugin(): string {
		return self::WORKSPACE_PLUGIN;
	}

	public function getWorkspacePluginPath(): string {
		return self::WORKSPACE_PLUGIN_PATH;
	}

	public function isGitRequired(): bool {
		return $this->configuration->getBool('evolution', 'git_required', true);
	}

	public function getAgentId(): string {
		$id = strtolower(trim($this->configuration->getString('evolution', 'agent', 'evolution')));
		$id = preg_replace('/[^a-z0-9._-]+/', '', $id) ?? '';
		return $id !== '' ? $id : 'evolution';
	}

	public function getDataDirectory(): string {
		return trim($this->configuration->getString('directories', 'data', ''));
	}

	public function getSystemPromptFile(): string {
		return DIR_PLUGIN . 'Evolution' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'system-prompt.md';
	}
}
