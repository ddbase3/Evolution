<?php declare(strict_types=1);

namespace Evolution\Service;

use AssistantFoundation\Dto\AgentExecutionRequest;
use AssistantFoundation\Dto\AgentExecutionResult;
use AssistantFoundation\Dto\AgentInteractionRequest;
use AssistantFoundation\Dto\AgentInteractionResponse;
use AssistantFoundation\Dto\AgentResume;
use AssistantFoundation\Dto\AgentResult;
use AssistantFoundation\Dto\AgentStageTraceEntry;
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

		$baseSourceFingerprint = $this->workspace->createSourceFingerprint();
		$baseHead = $this->configuration->isGitRequired() ? $this->workspace->getGitHead() : '';
		$result = $this->executeAgent(
			'analyze',
			$this->buildAnalyzeInstruction($prompt),
			[
				'evolution_mode' => 'analyze',
				'evolution_workspace' => $this->configuration->getWorkspace(),
				'evolution_write_root' => $this->configuration->getWorkspacePluginPath()
			]
		);
		$this->assertSuccessfulAgentResult($result, 'analysis');

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
			'base_source_fingerprint' => $baseSourceFingerprint,
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
		$changeId = $this->normalizeChangeId($changeId);

		$health = $this->health->check();
		if (($health['apply_ready'] ?? false) !== true) {
			throw new RuntimeException('Evolution apply is not ready. Resolve the failing self-checks and commit or discard existing Git changes first.');
		}

		$state = $this->requireStateStore();
		$change = $this->loadChange($state, $changeId);
		if (($change['status'] ?? '') !== 'planned') {
			throw new RuntimeException('Evolution change is not in planned state: ' . (string)($change['status'] ?? 'unknown'));
		}

		$this->acquireApplyLock($state, $changeId);
		$baseGitSnapshot = [];
		try {
			$this->workspace->assertSourceFingerprint(trim((string)($change['base_source_fingerprint'] ?? '')));
			$baseGitSnapshot = $this->configuration->isGitRequired() ? $this->workspace->createGitSnapshot() : [];
			$change = array_merge($change, [
				'status' => 'applying',
				'apply_started_at' => time(),
				'base_git_snapshot' => $baseGitSnapshot
			]);
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, $change, self::CHANGE_TTL_SECONDS);

			$result = $this->executeAgent(
				'apply',
				$this->buildApplyInstruction($change),
				$this->buildApplyContext($changeId)
			);

			if ($result->getAgentResult()?->isSuspended()) {
				return $this->storeSuspendedApply($state, $change, $changeId, $result);
			}

			$this->assertSuccessfulAgentResult($result, 'apply');
			return $this->finalizeAppliedChange($state, $change, $changeId, $result, $baseGitSnapshot);
		} catch (Throwable $e) {
			$this->markApplyFailed($state, $change, $changeId, $baseGitSnapshot, $e);
			throw new RuntimeException($e->getMessage(), 0, $e);
		} finally {
			$this->releaseApplyLock($state);
		}
	}

	/** @return array<string,mixed> */
	public function approveApply(string $changeId, string $resumeHandle): array {
		$changeId = $this->normalizeChangeId($changeId);
		$resumeHandle = trim($resumeHandle);
		if ($resumeHandle === '') {
			throw new RuntimeException('Missing MissionBay resume handle for Evolution approval.');
		}

		$state = $this->requireStateStore();
		$change = $this->loadChange($state, $changeId);
		if (($change['status'] ?? '') !== 'awaiting_approval') {
			throw new RuntimeException('Evolution change is not awaiting approval: ' . (string)($change['status'] ?? 'unknown'));
		}
		$storedHandle = trim((string)($change['resume_handle'] ?? ''));
		if ($storedHandle === '' || !hash_equals($storedHandle, $resumeHandle)) {
			throw new RuntimeException('Evolution approval resume handle does not match the pending change.');
		}

		$requests = is_array($change['interaction_requests'] ?? null) ? $change['interaction_requests'] : [];
		if ($requests === []) {
			throw new RuntimeException('Evolution change has no pending MissionBay approval requests.');
		}

		$responses = [];
		foreach ($requests as $request) {
			if (!is_array($request)) {
				throw new RuntimeException('Stored MissionBay approval request is invalid.');
			}
			$requestId = trim((string)($request['id'] ?? ''));
			$kind = trim((string)($request['kind'] ?? ''));
			if ($requestId === '' || $kind !== AgentInteractionRequest::KIND_APPROVAL) {
				throw new RuntimeException('Evolution v1 can resume only explicit MissionBay approval requests.');
			}
			$responses[] = new AgentInteractionResponse(
				$requestId,
				AgentInteractionResponse::DECISION_APPROVE
			);
		}

		$this->acquireApplyLock($state, $changeId);
		$baseGitSnapshot = is_array($change['base_git_snapshot'] ?? null) ? $change['base_git_snapshot'] : [];
		try {
			$this->workspace->assertSourceFingerprint(trim((string)($change['resume_source_fingerprint'] ?? '')));
			$resume = new AgentResume($resumeHandle, $responses);
			$change = array_merge($change, [
				'status' => 'applying',
				'resume_handle' => '',
				'interaction_requests' => [],
				'resume_started_at' => time()
			]);
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, $change, self::CHANGE_TTL_SECONDS);

			$result = $this->executeAgent(
				'apply',
				'Continue the approved Evolution apply operation from the persisted MissionBay suspension.',
				$this->buildApplyContext($changeId),
				$resume
			);

			if ($result->getAgentResult()?->isSuspended()) {
				return $this->storeSuspendedApply($state, $change, $changeId, $result);
			}

			$this->assertSuccessfulAgentResult($result, 'apply resume');
			return $this->finalizeAppliedChange($state, $change, $changeId, $result, $baseGitSnapshot);
		} catch (Throwable $e) {
			$this->markApplyFailed($state, $change, $changeId, $baseGitSnapshot, $e);
			throw new RuntimeException($e->getMessage(), 0, $e);
		} finally {
			$this->releaseApplyLock($state);
		}
	}

	/** @return array<string,mixed> */
	public function testLlm(): array {
		return $this->health->testLlm();
	}

	/** @return array<string,mixed> */
	public function testAgent(): array {
		$health = $this->health->check();
		if (($health['analysis_ready'] ?? false) !== true) {
			return [
				'ok' => false,
				'message' => 'Agent tool-loop test is unavailable until the required Evolution analysis checks pass.'
			];
		}

		$result = $this->executeAgent(
			'analyze',
			'Diagnostic run only. Call evolution_workspace_info exactly once. After the tool result, reply exactly with EVOLUTION_AGENT_OK. Do not call mutation tools.',
			[
				'evolution_mode' => 'analyze',
				'evolution_workspace' => $this->configuration->getWorkspace(),
				'evolution_write_root' => $this->configuration->getWorkspacePluginPath(),
				'evolution_diagnostic' => true
			]
		);
		$this->assertSuccessfulAgentResult($result, 'tool-loop test');

		$agentResult = $result->getAgentResult();
		$execution = $agentResult?->getState()->getExecution();
		$toolCalls = $execution?->getExecutedToolCalls() ?? [];
		if ($toolCalls === []) {
			return [
				'ok' => false,
				'message' => 'MissionBay agent completed the diagnostic without executing a tool. The configured model did not exercise the tool loop.'
			];
		}

		$text = trim($this->extractAssistantText($result));
		return [
			'ok' => $text === 'EVOLUTION_AGENT_OK',
			'message' => $text === 'EVOLUTION_AGENT_OK'
				? 'MissionBay agent tool loop succeeded.'
				: 'MissionBay agent tool loop executed, but the diagnostic final response was unexpected: ' . ($text !== '' ? $text : '(empty)'),
			'tool_calls' => count($toolCalls)
		];
	}

	private function executeAgent(
		string $mode,
		string $instruction,
		array $context,
		?AgentResume $resume = null
	): AgentExecutionResult {
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

		$inputs = [
			'system' => $effectiveSystemPrompt,
			'prompt' => $instruction,
			'mode' => $mode
		];
		if ($resume instanceof AgentResume) {
			$inputs['resume'] = $resume->toArray();
		}

		$request = new AgentExecutionRequest($agentSettings, $inputs, $context);
		return $executionService->execute($request);
	}

	/** @param array<string,mixed> $change */
	private function buildApplyInstruction(array $change): string {
		return "Implement the approved EvolutionWorkspace change now.\n\n"
			. "Original user request:\n" . trim((string)($change['prompt'] ?? '')) . "\n\n"
			. "Approved plan:\n" . trim((string)($change['plan'] ?? '')) . "\n\n"
			. "Mutation is restricted to plugin/EvolutionWorkspace. Implement the approved plan there; do not merely describe code. "
			. "Read other BASE3 source only when needed as a contract or implementation reference. Keep plugin init() limited to composition, preserve local coding conventions, and do not modify existing migrations. "
			. "Run the available validation tools before the final response.";
	}

	private function buildAnalyzeInstruction(string $prompt): string {
		return "Analyze this requested change for plugin/EvolutionWorkspace without modifying files:\n\n"
			. $prompt
			. "\n\nStart with plugin/EvolutionWorkspace. Search before listing broad trees and read only the files needed to understand the requested change. "
			. "Inspect BASE3 framework, MissionBay, other plugins, settings or database schema only when the requested change actually depends on them. Stop exploring once the relevant contract and local implementation pattern are clear. "
			. "Return a concise concrete implementation plan with files to create/change/delete, composition or ClassMap impact, migration impact only when relevant, and validation steps. "
			. "All planned source mutations must stay inside plugin/EvolutionWorkspace. If the request cannot be implemented safely with that boundary, state the exact blocker.";
	}

	private function storeSuspendedApply(
		IStateStore $state,
		array $change,
		string $changeId,
		AgentExecutionResult $result
	): array {
		$agentResult = $result->getAgentResult();
		$suspension = $agentResult?->getState()->getSuspension();
		if ($agentResult === null || !$agentResult->isSuspended() || $suspension === null || !$suspension->isSuspended()) {
			throw new RuntimeException('MissionBay reported a suspended Evolution run without suspension state.');
		}
		if ($suspension->getStatus() !== 'awaiting_approval') {
			throw new RuntimeException('Evolution v1 supports only MissionBay approval suspensions, received: ' . $suspension->getStatus());
		}

		$resumeHandle = trim($suspension->getResumeHandle());
		if ($resumeHandle === '') {
			throw new RuntimeException('MissionBay approval suspension has no resume handle.');
		}

		$publicRequests = [];
		$storedRequests = [];
		foreach ($suspension->getInteractionRequests() as $request) {
			if (!$request instanceof AgentInteractionRequest) {
				throw new RuntimeException('MissionBay approval suspension contains an invalid interaction request.');
			}
			if ($request->getKind() !== AgentInteractionRequest::KIND_APPROVAL) {
				throw new RuntimeException('Evolution v1 supports only explicit MissionBay approval requests.');
			}
			$storedRequests[] = [
				'id' => $request->getId(),
				'kind' => $request->getKind()
			];
			$publicRequests[] = [
				'id' => $request->getId(),
				'kind' => $request->getKind(),
				'action' => $request->getAction()->getName(),
				'title' => $request->getTitle(),
				'message' => $request->getMessage(),
				'summary' => $this->normalizeApprovalSummary($request->getSummary()),
				'risk' => $request->getRisk()
			];
		}
		if ($storedRequests === []) {
			throw new RuntimeException('MissionBay approval suspension contains no interaction requests.');
		}

		$change = array_merge($change, [
			'status' => 'awaiting_approval',
			'resume_handle' => $resumeHandle,
			'interaction_requests' => $storedRequests,
			'resume_source_fingerprint' => $this->workspace->createSourceFingerprint(),
			'approval_requested_at' => time(),
			'warnings' => $result->getWarnings()
		]);
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, $change, self::CHANGE_TTL_SECONDS);

		return [
			'ok' => true,
			'status' => 'awaiting_approval',
			'message' => 'MissionBay requires approval for the concrete pending source mutation before execution.',
			'change_id' => $changeId,
			'resume_handle' => $resumeHandle,
			'interaction_requests' => $publicRequests,
			'warnings' => $result->getWarnings()
		];
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private function normalizeApprovalSummary(array $summary): array {
		$result = [];
		foreach ($summary as $key => $value) {
			if (is_scalar($value) || $value === null) {
				$text = $value === null ? 'Not specified' : (string)$value;
				$result[(string)$key] = strlen($text) > 1200 ? substr($text, 0, 1200) . '…' : $text;
				continue;
			}
			$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$text = is_string($json) ? $json : get_debug_type($value);
			$result[(string)$key] = strlen($text) > 1200 ? substr($text, 0, 1200) . '…' : $text;
		}
		return $result;
	}

	/** @param array<string,mixed> $change @param array<string,mixed> $baseGitSnapshot @return array<string,mixed> */
	private function finalizeAppliedChange(
		IStateStore $state,
		array $change,
		string $changeId,
		AgentExecutionResult $result,
		array $baseGitSnapshot
	): array {
		$baseHead = trim((string)($baseGitSnapshot['head'] ?? ''));
		$changedPaths = $this->configuration->isGitRequired() ? $this->workspace->getChangedPaths($baseHead) : [];
		if ($this->configuration->isGitRequired() && $changedPaths === []) {
			throw new RuntimeException('Apply completed without EvolutionWorkspace source changes. The approved plan was not implemented.');
		}

		$validation = $this->validateAppliedChange($baseHead);
		if (($validation['ok'] ?? false) !== true) {
			$rollback = $this->rollbackFailedChange($baseGitSnapshot);
			$failed = array_merge($change, [
				'status' => 'failed',
				'apply_finished_at' => time(),
				'validation' => $validation,
				'rollback' => $rollback,
				'agent_output' => $this->extractAssistantText($result),
				'warnings' => $result->getWarnings(),
				'resume_handle' => '',
				'interaction_requests' => []
			]);
			$state->set(self::CHANGE_KEY_PREFIX . $changeId, $failed, self::CHANGE_TTL_SECONDS);
			return [
				'ok' => false,
				'message' => 'Generated EvolutionWorkspace change failed validation and was reverted to the accepted Git revision.',
				'validation' => $validation,
				'rollback' => $rollback,
				'warnings' => $result->getWarnings()
			];
		}

		$diff = $this->configuration->isGitRequired() ? $this->workspace->getGitDiff($baseHead) : '';
		$done = array_merge($change, [
			'status' => 'applied',
			'apply_finished_at' => time(),
			'validation' => $validation,
			'changed_paths' => $changedPaths,
			'agent_output' => $this->extractAssistantText($result),
			'warnings' => $result->getWarnings(),
			'resume_handle' => '',
			'interaction_requests' => []
		]);
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, $done, self::CHANGE_TTL_SECONDS);

		return [
			'ok' => true,
			'status' => 'applied',
			'message' => 'Approved EvolutionWorkspace change was applied and validated.',
			'change_id' => $changeId,
			'changed_paths' => $changedPaths,
			'diff' => $diff,
			'validation' => $validation,
			'agent_output' => $this->extractAssistantText($result),
			'warnings' => $result->getWarnings()
		];
	}

	/** @param array<string,mixed> $change @param array<string,mixed> $baseGitSnapshot */
	private function markApplyFailed(
		IStateStore $state,
		array $change,
		string $changeId,
		array $baseGitSnapshot,
		Throwable $error
	): void {
		$rollback = null;
		if ($this->configuration->isGitRequired() && $baseGitSnapshot !== []) {
			$rollback = $this->rollbackFailedChange($baseGitSnapshot);
		}
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, array_merge($change, [
			'status' => 'failed',
			'apply_finished_at' => time(),
			'error' => $error->getMessage(),
			'rollback' => $rollback,
			'resume_handle' => '',
			'interaction_requests' => []
		]), self::CHANGE_TTL_SECONDS);
	}

	/** @return array<string,mixed> */
	private function buildApplyContext(string $changeId): array {
		return [
			'evolution_mode' => 'apply',
			'evolution_change_id' => $changeId,
			'evolution_workspace' => $this->configuration->getWorkspace(),
			'evolution_write_root' => $this->configuration->getWorkspacePluginPath()
		];
	}

	private function normalizeChangeId(string $changeId): string {
		$changeId = strtolower(trim($changeId));
		if (!preg_match('/^[a-f0-9]{24}$/', $changeId)) {
			throw new RuntimeException('Invalid Evolution change id.');
		}
		return $changeId;
	}

	/** @return array<string,mixed> */
	private function loadChange(IStateStore $state, string $changeId): array {
		$change = $state->get(self::CHANGE_KEY_PREFIX . $changeId);
		if (!is_array($change) || ($change['id'] ?? '') !== $changeId) {
			throw new RuntimeException('Evolution change plan was not found or has expired: ' . $changeId);
		}
		return $change;
	}

	private function acquireApplyLock(IStateStore $state, string $changeId): void {
		if (!$state->setIfNotExists(self::APPLY_LOCK_KEY, [
			'change_id' => $changeId,
			'started_at' => time()
		], self::APPLY_LOCK_TTL_SECONDS)) {
			throw new RuntimeException('Another Evolution apply operation is already running.');
		}
	}

	private function releaseApplyLock(IStateStore $state): void {
		try {
			$state->delete(self::APPLY_LOCK_KEY);
		} catch (Throwable) {
		}
	}

	/** @return array<string,mixed> */
	private function validateAppliedChange(?string $baseHead = null): array {
		$lint = $this->workspace->validateChangedPhp($baseHead);
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

	private function assertSuccessfulAgentResult(AgentExecutionResult $result, string $phase): void {
		$agentResult = $result->getAgentResult();
		if ($agentResult !== null && $agentResult->hasFailure()) {
			$resultState = $agentResult->getState()->getResult();
			$failureCode = trim((string)($resultState?->getFailureCode() ?? ''));
			$failureMessage = trim((string)($resultState?->getFailureMessage() ?? ''));
			$failureDetail = $resultState?->getFailureDetail() ?? [];

			if ($failureMessage === '') {
				$metadata = $agentResult->getMetadata();
				$failureMessage = trim((string)($metadata['error'] ?? $metadata['message'] ?? 'Agent runtime reported a failed execution.'));
			}

			$parts = ['MissionBay agent ' . $phase . ' failed'];
			if ($failureCode !== '') {
				$parts[0] .= ' [' . $failureCode . ']';
			}
			$parts[0] .= ': ' . $failureMessage;

			$detail = $this->formatFailureDetail($failureDetail);
			if ($detail !== '') {
				$parts[] = 'Detail: ' . $detail;
			}

			throw new RuntimeException(implode("\n", $parts));
		}

		$output = $result->getOutput();
		$assistant = is_array($output['assistant'] ?? null) ? $output['assistant'] : [];
		$error = $assistant['error'] ?? ($output['error'] ?? null);
		if (is_scalar($error) && trim((string)$error) !== '') {
			throw new RuntimeException('MissionBay agent ' . $phase . ' failed: ' . trim((string)$error));
		}

		$warning = is_scalar($assistant['warning'] ?? null)
			? trim((string)$assistant['warning'])
			: '';
		if ($warning !== '') {
			$status = is_scalar($assistant['status'] ?? null)
				? trim((string)$assistant['status'])
				: ($agentResult?->getStatus() ?? 'unknown');
			$message = 'MissionBay agent ' . $phase . ' did not produce a complete final response [' . $warning . ']. '
				. 'Status: ' . ($status !== '' ? $status : 'unknown') . '. No Evolution change plan was stored.';

			$partialDetail = $this->getPartialResponseDetail($agentResult);
			if ($partialDetail !== '') {
				$message .= ' Detail: ' . $partialDetail;
			}

			throw new RuntimeException($message);
		}

		if ($agentResult !== null && !$agentResult->isCompleted()) {
			throw new RuntimeException(
				'MissionBay agent ' . $phase . ' ended with non-terminal status: ' . $agentResult->getStatus() . '.'
			);
		}
	}

	private function getPartialResponseDetail(?AgentResult $agentResult): string {
		$execution = $agentResult?->getState()->getExecution();
		if ($execution === null) {
			return '';
		}

		$trace = array_reverse($execution->getStageTrace());
		foreach ($trace as $entry) {
			if (!$entry instanceof AgentStageTraceEntry) {
				continue;
			}

			$metadata = $entry->getMetadata();
			if (($metadata['recovered_from_model_error'] ?? false) !== true) {
				continue;
			}

			$parts = [
				'stage=' . $entry->getStageName(),
				'iteration=' . $entry->getIteration()
			];

			$errorType = trim((string)($metadata['error_type'] ?? ''));
			if ($errorType !== '') {
				$parts[] = 'error_type=' . $errorType;
			}

			$errorMessage = trim((string)($metadata['error_message'] ?? ''));
			if ($errorMessage !== '') {
				$parts[] = 'error=' . $errorMessage;
			}

			$errorCode = $metadata['error_code'] ?? null;
			if (is_scalar($errorCode) && trim((string)$errorCode) !== '') {
				$parts[] = 'error_code=' . (string)$errorCode;
			}

			return implode(', ', $parts);
		}

		return 'iteration=' . $execution->getIteration()
			. '/' . $execution->getMaxIterations()
			. ', executed_tool_calls=' . count($execution->getExecutedToolCalls());
	}

	/** @param array<string,mixed> $detail */
	private function formatFailureDetail(array $detail): string {
		if ($detail === []) {
			return '';
		}

		$message = $detail['message'] ?? null;
		if (is_scalar($message) && trim((string)$message) !== '') {
			return trim((string)$message);
		}

		$json = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json) || $json === '{}' || $json === 'null') {
			return '';
		}

		return $json;
	}

	private function extractAssistantText(AgentExecutionResult $result): string {
		$this->assertSuccessfulAgentResult($result, 'execution');

		$output = $result->getOutput();
		$agentResult = $result->getAgentResult();
		if ($output === [] && $agentResult !== null) {
			$output = $agentResult->getOutput();
		}

		$assistant = is_array($output['assistant'] ?? null) ? $output['assistant'] : [];
		$message = $assistant['message'] ?? null;
		if (is_array($message)) {
			$content = $this->normalizeMessageContent($message['content'] ?? null);
			if ($content !== '') {
				return trim($content);
			}
		}
		if (is_scalar($message)) {
			return trim((string)$message);
		}

		$content = $this->normalizeMessageContent($assistant['content'] ?? null);
		if ($content !== '') {
			return trim($content);
		}

		$error = $assistant['error'] ?? ($output['error'] ?? null);
		if (is_scalar($error) && trim((string)$error) !== '') {
			throw new RuntimeException('MissionBay agent failed: ' . trim((string)$error));
		}

		if ($agentResult?->hasFailure()) {
			$metadata = $agentResult->getMetadata();
			$error = trim((string)($metadata['error'] ?? $metadata['message'] ?? 'Agent runtime reported a failed execution.'));
			throw new RuntimeException('MissionBay agent failed: ' . $error);
		}

		$terminalNodes = array_values(array_filter(
			array_map('strval', array_keys($output)),
			static fn(string $nodeId): bool => $nodeId !== ''
		));
		$status = $agentResult?->getStatus() ?? 'unknown';
		$details = $terminalNodes === []
			? 'No terminal node output was returned.'
			: 'Terminal nodes: ' . implode(', ', $terminalNodes) . '.';

		throw new RuntimeException(
			'MissionBay agent returned no assistant message. Status: ' . $status . '. ' . $details
		);
	}

	private function normalizeMessageContent(mixed $content): string {
		if ($content === null) {
			return '';
		}

		if (is_string($content)) {
			return $content;
		}

		if (is_bool($content)) {
			return $content ? 'true' : 'false';
		}

		if (is_int($content) || is_float($content)) {
			return (string)$content;
		}

		$json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return is_string($json) && $json !== 'null' ? $json : '';
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
