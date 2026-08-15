<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Configuration\Api\IConfiguration;

final class EvolutionConfiguration {

	public const WORKSPACE_PLUGIN = 'EvolutionWorkspace';
	public const WORKSPACE_PLUGIN_PATH = 'plugin/EvolutionWorkspace';
	public const PLANNING_MODULE_IMPLEMENTATION = 'evolutionplanningmodule';
	public const PLANNING_MODULE_COMPONENT = 'evolution-planning';

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

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function prepareAgentSettings(array $settings): array {
		$existingExpertOverrides = $this->toBool($settings['expert_overrides_enabled'] ?? false);
		$sources = $existingExpertOverrides && is_array($settings['capability_sources'] ?? null)
			? $settings['capability_sources']
			: [];
		$modules = is_array($sources['modules'] ?? null) ? $sources['modules'] : [];
		$modules = array_values(array_filter(array_map(
			static fn(mixed $value): string => strtolower(trim((string)$value)),
			$modules
		), static fn(string $value): bool => $value !== '' && $value !== self::PLANNING_MODULE_IMPLEMENTATION));
		$modules[] = self::PLANNING_MODULE_COMPONENT;
		$modules = array_values(array_unique($modules));

		$sources['modules'] = $modules;
		if (!array_key_exists('strict', $sources)) {
			$sources['strict'] = true;
		}
		$settings['expert_overrides_enabled'] = true;
		$settings['capability_sources'] = $sources;

		if (!$existingExpertOverrides) {
			unset($settings['capability_selection']);
		}

		return $settings;
	}

	private function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return $value !== 0;
		}
		return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'off', 'no'], true);
	}
}
