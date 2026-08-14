<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Configuration\Api\IConfiguration;

final class EvolutionConfiguration {

	public function __construct(
		private readonly IConfiguration $configuration
	) {}

	public function getWorkspace(): string {
		return trim($this->configuration->getString('evolution', 'workspace', ''));
	}

	public function isGitRequired(): bool {
		return $this->configuration->getBool('evolution', 'git_required', true);
	}

	public function isFrameworkWriteEnabled(): bool {
		return $this->configuration->getBool('evolution', 'framework_write', false);
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
