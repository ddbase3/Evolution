<?php declare(strict_types=1);

namespace Evolution\Service;

use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use Base3\Api\IContainer;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use MissionBay\Service\AgentExecutionService;
use RuntimeException;
use Throwable;

final class EvolutionAgentService {

	private const CHANGE_KEY_PREFIX = 'evolution.change.';
	private const APPLY_LOCK_KEY = 'locks.evolution.apply';
	private const CHANGE_TTL_SECONDS = 86400;
	private const APPLY_LOCK_TTL_SECONDS = 1800;

	public function __construct(
		private readonly EvolutionConfiguration $configuration,
		private readonly IContainer $container,
		private readonly EvolutionWorkspaceService $workspace,
		private readonly EvolutionHealthService $health
	) {}

	/** @return array<string,mixed> */
	public function analyze(string $prompt): array {
		$prompt = trim($prompt);
		if ($prompt === '') {
			throw new RuntimeException('Please describe the requested change.');
		}

		$health = $this->health->check();
		if (($health['analysis_ready'] ?? false) !== true) {
			throw new RuntimeException('Evolution analysis is not ready. Resolve the failing self-checks first.');
		}

		$baseGitSnapshot = $this->configuration->isGitRequired() ? $this->workspace->createGitSnapshot() : [];
		$baseHead = trim((string)($baseGitSnapshot['repositories']['.'] ?? ''));
		$result = $this->executeAgent(
			'analyze',
			$this->buildAnalyzeInstruction($prompt),
			[
				'evolution_mode' => 'analyze',
				'evolution_workspace' => $this->configuration->getWorkspace()
			]
		);

		$plan = trim($this->extractAssistantText($result));
		if ($plan === '') {
			throw new RuntimeException('MissionBay agent returned no change plan.');
		}

		$changeId = bin2hex(random_bytes(12));
		$state = $this->requireStateStore();
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, [
			'id' => $changeId,
			'status' => 'planned',
			'prompt' => $prompt,
			'plan' => $plan,
			'base_head' => $baseHead,
			'base_git_snapshot' => $baseGitSnapshot,
			'created_at' => time(),
			'warnings' => $result->getWarnings()
		], self::CHANGE_TTL_SECONDS);

		return [
			'ok' => true,
			'change_id' => $changeId,
			'plan' => $plan,
			'base_head' => $baseHead,
			'warnings' => $result->getWarnings()
		];
	}

	/** @return array<string,mixed> */
	public function apply(string $changeId): array {
		$changeId = strtolower(trim($changeId));
		if (!preg_match('/^[a-f0-9]{24}$/', $changeId)) {
			throw new RuntimeException('Invalid Evolution change id.');
		}

		$health = $this->health->check();
		if (($health['apply_ready'] ?? false) !== true) {
			throw new RuntimeException('Evolution apply is not ready. Resolve the failing self-checks and commit or discard existing Git changes first.');
		}

		$state = $this->requireStateStore();
		$change = $state->get(self::CHANGE_KEY_PREFIX . $changeId);
		if (!is_array($change) || ($change['id'] ?? '') !== $changeId) {
			throw new RuntimeException('Evolution change plan was not found or has expired: ' . $changeId);
		}
		if (($change['status'] ?? '') !== 'planned') {
			throw new RuntimeException('Evolution change is not in planned state: ' . (string)($change['status'] ?? 'unknown'));
		}

		if (!$state->setIfNotExists(self::APPLY_LOCK_KEY, [
			'change_id' => $changeId,
			'started_at' => time()
		], self::APPLY_LOCK_TTL_SECONDS)) {
			throw new RuntimeException('Another Evolution apply operation is already running.');
		}

		$baseGitSnapshot = is_array($change['base_git_snapshot'] ?? null) ? $change['base_git_snapshot'] : [];
		try {
			$this->assertAcceptedBase($baseGitSnapshot);
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, array_merge($change, [
				'status' => 'applying',
				'apply_started_at' => time()
			]), self::CHANGE_TTL_SECONDS);

			$result = $this->executeAgent(
				'apply',
				$this->buildApplyInstruction($change),
				[
					'evolution_mode' => 'apply',
					'evolution_change_id' => $changeId,
					'evolution_workspace' => $this->configuration->getWorkspace()
				]
			);

			$changedPaths = $this->configuration->isGitRequired() ? $this->workspace->getChangedPaths() : [];
			if ($this->configuration->isGitRequired() && $changedPaths === []) {
				throw new RuntimeException('Apply completed without source changes. The approved plan was not implemented.');
			}

			$validation = $this->validateAppliedChange();
			if (($validation['ok'] ?? false) !== true) {
				$rollback = $this->rollbackFailedChange($baseGitSnapshot);
				$failed = array_merge($change, [
					'status' => 'failed',
					'apply_finished_at' => time(),
					'validation' => $validation,
					'rollback' => $rollback,
					'agent_output' => $this->extractAssistantText($result),
					'warnings' => $result->getWarnings()
				]);
				$state->set(self::CHANGE_KEY_PREFIX . $changeId, $failed, self::CHANGE_TTL_SECONDS);
				return [
					'ok' => false,
					'message' => 'Generated change failed validation and was reverted to the accepted Git revision.',
					'validation' => $validation,
					'rollback' => $rollback,
					'warnings' => $result->getWarnings()
				];
			}

			$diff = $this->configuration->isGitRequired() ? $this->workspace->getGitDiff() : '';
			$done = array_merge($change, [
				'status' => 'applied',
				'apply_finished_at' => time(),
				'validation' => $validation,
				'changed_paths' => $changedPaths,
				'agent_output' => $this->extractAssistantText($result),
				'warnings' => $result->getWarnings()
			]);
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, $done, self::CHANGE_TTL_SECONDS);

			return [
				'ok' => true,
				'message' => 'Approved Evolution change was applied and validated.',
				'change_id' => $changeId,
				'changed_paths' => $changedPaths,
				'diff' => $diff,
				'validation' => $validation,
				'agent_output' => $this->extractAssistantText($result),
				'warnings' => $result->getWarnings()
			];
		} catch (Throwable $e) {
			$rollback = null;
			if ($this->configuration->isGitRequired() && $baseGitSnapshot !== [] && !$this->workspace->isGitClean()) {
				$rollback = $this->rollbackFailedChange($baseGitSnapshot);
			}
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, array_merge($change, [
				'status' => 'failed',
				'apply_finished_at' => time(),
				'error' => $e->getMessage(),
				'rollback' => $rollback
			]), self::CHANGE_TTL_SECONDS);
			throw new RuntimeException($e->getMessage(), 0, $e);
		} finally {
			try {
				$state->delete(self::APPLY_LOCK_KEY);
			} catch (Throwable) {
			}
		}
	}

	/** @return array<string,mixed> */
	public function testLlm(): array {
		return $this->health->testLlm();
	}

	private function executeAgent(string $mode, string $instruction, array $context): AgentExecutionResult {
		$settingsStore = $this->requireSettingsStore();
		$agentId = $this->configuration->getAgentId();
		$agentSettings = $settingsStore->get('agent', $agentId, []);
		if ($agentSettings === []) {
			throw new RuntimeException('Evolution agent settings not found: agent/' . $agentId);
		}

		$systemPrompt = $this->loadSystemPrompt();
		$storedSystemPrompt = trim((string)($agentSettings['system_prompt'] ?? ''));
		$effectiveSystemPrompt = trim($systemPrompt . ($storedSystemPrompt !== '' ? "\n\n" . $storedSystemPrompt : ''));

		$executionService = $this->container->has(AgentExecutionService::class)
			? $this->container->get(AgentExecutionService::class)
			: null;
		if (!$executionService instanceof AgentExecutionService) {
			throw new RuntimeException('MissionBay AgentExecutionService is not available.');
		}

		$request = new AgentExecutionRequest(
			$agentSettings,
			[
				'system' => $effectiveSystemPrompt,
				'prompt' => $instruction,
				'mode' => $mode
			],
			$context
		);

		return $executionService->execute($request);
	}

	/** @param array<string,mixed> $change */
	private function buildApplyInstruction(array $change): string {
		return "Implement the approved Evolution change now.\n\n"
			. "Original user request:\n" . trim((string)($change['prompt'] ?? '')) . "\n\n"
			. "Approved plan:\n" . trim((string)($change['plan'] ?? '')) . "\n\n"
			. "Use the Evolution workspace tools to implement exactly this plan. Do not merely describe code. "
			. "Respect the current BASE3 architecture and local coding style. Do not modify existing migrations. "
			. "Do not change framework source when framework_write is disabled. Run available validation tools before your final response.";
	}

	private function buildAnalyzeInstruction(string $prompt): string {
		return "Analyze this requested BASE3 application change without modifying files:\n\n"
			. $prompt
			. "\n\nInspect the actual source, BASE3 structure, relevant settings and database schema with the available read-only tools. "
			. "Return a concrete implementation plan: affected plugin/domain, files to create/change/delete, service or ClassMap impact, database migration impact, data-safety concerns and validation steps. "
			. "If the request cannot be implemented safely with the available information, state the exact blocking reason instead of inventing an architecture.";
	}

	/** @return array<string,mixed> */
	private function validateAppliedChange(): array {
		$lint = $this->workspace->validateChangedPhp();
		if (($lint['ok'] ?? false) !== true) {
			return ['ok' => false, 'step' => 'php_lint', 'php_lint' => $lint];
		}

		$classMap = $this->workspace->refreshClassMap();
		if (($classMap['ok'] ?? false) !== true) {
			return ['ok' => false, 'step' => 'classmap', 'php_lint' => $lint, 'classmap' => $classMap];
		}

		$tests = $this->workspace->runTests();
		if (($tests['ok'] ?? false) !== true) {
			return ['ok' => false, 'step' => 'tests', 'php_lint' => $lint, 'classmap' => $classMap, 'tests' => $tests];
		}

		return ['ok' => true, 'php_lint' => $lint, 'classmap' => $classMap, 'tests' => $tests];
	}

	/** @param array<string,mixed> $baseGitSnapshot @return array<string,mixed> */
	private function rollbackFailedChange(array $baseGitSnapshot): array {
		if (!$this->configuration->isGitRequired()) {
			return ['ok' => false, 'message' => 'Automatic rollback requires [evolution] git_required = true.'];
		}
		$rollback = $this->workspace->rollbackToSnapshot($baseGitSnapshot);
		if (($rollback['ok'] ?? false) === true) {
			$rollback['classmap'] = $this->workspace->refreshClassMap();
		}
		return $rollback;
	}

	/** @param array<string,mixed> $baseGitSnapshot */
	private function assertAcceptedBase(array $baseGitSnapshot): void {
		if (!$this->configuration->isGitRequired()) {
			return;
		}
		$this->workspace->assertGitSnapshot($baseGitSnapshot);
	}

	private function extractAssistantText(AgentExecutionResult $result): string {
		$output = $result->getOutput();
		$agentResult = $result->getAgentResult();
		if ($agentResult !== null && $agentResult->getOutput() !== []) {
			$output = $agentResult->getOutput();
		}

		$assistant = is_array($output['assistant'] ?? null) ? $output['assistant'] : [];
		$message = $assistant['message'] ?? null;
		if (is_array($message)) {
			$content = $message['content'] ?? '';
			if (is_scalar($content)) {
				return trim((string)$content);
			}
		}
		if (is_scalar($message)) {
			return trim((string)$message);
		}

		$error = $assistant['error'] ?? ($output['error'] ?? null);
		if (is_scalar($error) && trim((string)$error) !== '') {
			throw new RuntimeException('MissionBay agent failed: ' . trim((string)$error));
		}

		return trim((string)($assistant['content'] ?? ''));
	}

	private function loadSystemPrompt(): string {
		$file = $this->configuration->getSystemPromptFile();
		$content = is_file($file) ? file_get_contents($file) : false;
		if (!is_string($content) || trim($content) === '') {
			throw new RuntimeException('Evolution system prompt is missing or empty: ' . $file);
		}
		return trim($content);
	}

	private function requireSettingsStore(): ISettingsStore {
		$service = $this->container->has(ISettingsStore::class) ? $this->container->get(ISettingsStore::class) : null;
		if (!$service instanceof ISettingsStore) {
			throw new RuntimeException('ISettingsStore is not available.');
		}
		return $service;
	}

	private function requireStateStore(): IStateStore {
		$service = $this->container->has(IStateStore::class) ? $this->container->get(IStateStore::class) : null;
		if (!$service instanceof IStateStore) {
			throw new RuntimeException('IStateStore is not available.');
		}
		return $service;
	}
}
