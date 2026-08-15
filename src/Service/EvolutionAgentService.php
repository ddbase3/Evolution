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
	private const CHANGE_FORMAT_VERSION = 5;
	private const APPLY_LOCK_TTL_SECONDS = 1800;
	private const APPLY_PLAN_TOOL = 'evolution_apply_plan';

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
				'evolution_mode' => 'plan',
				'evolution_workspace' => $this->configuration->getWorkspace(),
				'evolution_write_root' => $this->configuration->getWorkspacePluginPath()
			]
		);

		if ($result->getAgentResult()?->isSuspended()) {
			return $this->storePlannedChange(
				$this->requireStateStore(),
				$prompt,
				$baseHead,
				$baseSourceFingerprint,
				$result
			);
		}

		$this->assertSuccessfulAgentResult($result, 'analysis');
		$blocker = $this->parseBlockedAnalysis($this->extractAssistantText($result));
		return [
			'ok' => true,
			'applicable' => false,
			'change_id' => '',
			'plan' => $blocker,
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
		$this->assertCurrentChangeFormat($change);
		if (($change['status'] ?? '') !== 'planned') {
			throw new RuntimeException('Evolution change is not in planned state: ' . (string)($change['status'] ?? 'unknown'));
		}

		$resumeHandle = trim((string)($change['resume_handle'] ?? ''));
		$requestId = trim((string)($change['interaction_request_id'] ?? ''));
		if ($resumeHandle === '' || $requestId === '') {
			throw new RuntimeException('Evolution change has no resumable MissionBay plan approval. Re-run analysis.');
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

			$resume = new AgentResume($resumeHandle, [
				new AgentInteractionResponse($requestId, AgentInteractionResponse::DECISION_APPROVE)
			]);
			$result = $this->executeAgent(
				'apply',
				'',
				[
					'evolution_mode' => 'apply',
					'evolution_change_id' => $changeId,
					'evolution_workspace' => $this->configuration->getWorkspace(),
					'evolution_write_root' => $this->configuration->getWorkspacePluginPath()
				],
				$resume
			);

			$execution = $this->findApplyPlanExecution($result);
			if ($execution === null) {
				$this->assertSuccessfulAgentResult($result, 'apply');
				throw new RuntimeException('MissionBay resumed the approved Evolution plan without executing the stored apply-plan tool call.');
			}
			if (($execution['ok'] ?? false) !== true) {
				$error = trim((string)($execution['error'] ?? 'Approved Evolution plan execution failed.'));
				throw new RuntimeException($error !== '' ? $error : 'Approved Evolution plan execution failed.');
			}

			return $this->finalizeAppliedChange(
				$state,
				$change,
				$changeId,
				$result,
				$baseGitSnapshot,
				$this->getAgentCompletionWarning($result)
			);
		} catch (Throwable $e) {
			$this->markApplyFailed($state, $change, $changeId, $baseGitSnapshot, $e);
			throw new RuntimeException($e->getMessage(), 0, $e);
		} finally {
			$this->releaseApplyLock($state);
		}
	}

	/** @return array<string,mixed> */
	public function approveApply(string $changeId, string $resumeHandle): array {
		$change = $this->loadChange($this->requireStateStore(), $this->normalizeChangeId($changeId));
		$storedHandle = trim((string)($change['resume_handle'] ?? ''));
		if ($storedHandle === '' || !hash_equals($storedHandle, trim($resumeHandle))) {
			throw new RuntimeException('Evolution approval resume handle does not match the planned change.');
		}
		return $this->apply($changeId);
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
			'Diagnostic run only. Call evolution_workspace_info exactly once. After the tool result, reply exactly with EVOLUTION_AGENT_OK. Do not call evolution_apply_plan.',
			[
				'evolution_mode' => 'diagnostic',
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

	private function buildAnalyzeInstruction(string $prompt): string {
		return "Analyze this requested change for plugin/EvolutionWorkspace without executing source mutations:\n\n"
			. $prompt
			. "\n\nStart with plugin/EvolutionWorkspace. Search before listing broad trees and read only the files needed to understand the requested change. "
			. "BASE3 framework source lives below the application src/ tree; never invent vendor paths. For every BASE3 interface, abstract class or framework API that the implementation depends on, search the current application source and inspect its exact signature before proposing code. Do not infer missing methods, parameters, return types or registration requirements. "
			. "ClassMap-discoverable components do not need container registration solely for discovery. Modify plugin init() only when the inspected implementation proves actual service composition is required. "
			. "When the change is fully understood and implementable, do NOT return a textual READY plan. Instead call evolution_apply_plan exactly once with the complete final mutation set. Every write operation must contain the complete target file content. Every target path may occur only once. That tool is approval-bound: MissionBay will suspend before executing it, and the host will render its exact arguments as the proposed plan. "
			. "If an exact blocker remains, do not call evolution_apply_plan. Return exactly STATUS: BLOCKED, a blank line, and the concise blocker explanation. "
			. "Do not call evolution_apply_plan merely to test it. Once the tool call is emitted, analysis is complete.";
	}

	/** @return array<string,mixed> */
	private function storePlannedChange(
		IStateStore $state,
		string $prompt,
		string $baseHead,
		string $baseSourceFingerprint,
		AgentExecutionResult $result
	): array {
		$agentResult = $result->getAgentResult();
		$suspension = $agentResult?->getState()->getSuspension();
		if ($agentResult === null || !$agentResult->isSuspended() || $suspension === null || !$suspension->isSuspended()) {
			throw new RuntimeException('MissionBay reported a suspended Evolution analysis without suspension state.');
		}
		if ($suspension->getStatus() !== 'awaiting_approval') {
			throw new RuntimeException('Evolution planning expects a MissionBay approval suspension, received: ' . $suspension->getStatus());
		}

		$requests = $suspension->getInteractionRequests();
		if (count($requests) !== 1 || !$requests[0] instanceof AgentInteractionRequest) {
			throw new RuntimeException('Evolution planning requires exactly one MissionBay apply-plan approval request.');
		}
		$request = $requests[0];
		if ($request->getKind() !== AgentInteractionRequest::KIND_APPROVAL) {
			throw new RuntimeException('Evolution planning received a non-approval MissionBay interaction.');
		}
		$action = $request->getAction();
		if (!$this->isApplyPlanToolName($action->getName())) {
			throw new RuntimeException('Evolution analysis may suspend only for the complete apply-plan tool, received: ' . $action->getName());
		}

		$input = $action->getInput();
		$summary = trim((string)($input['summary'] ?? ''));
		if ($summary === '') {
			throw new RuntimeException('Evolution apply-plan tool call contains no plan summary.');
		}
		$operations = is_array($input['operations'] ?? null) ? $input['operations'] : [];
		$operations = $this->workspace->validatePlanOperations($operations);
		$plan = $this->formatPlan($summary, $operations);
		$resumeHandle = trim($suspension->getResumeHandle());
		if ($resumeHandle === '') {
			throw new RuntimeException('MissionBay plan approval suspension has no resume handle.');
		}

		$changeId = bin2hex(random_bytes(12));
		$change = [
			'id' => $changeId,
			'format_version' => self::CHANGE_FORMAT_VERSION,
			'status' => 'planned',
			'prompt' => $prompt,
			'plan' => $plan,
			'plan_summary' => $summary,
			'operations' => $operations,
			'base_head' => $baseHead,
			'base_source_fingerprint' => $baseSourceFingerprint,
			'resume_handle' => $resumeHandle,
			'interaction_request_id' => $request->getId(),
			'action_fingerprint' => $request->getActionFingerprint(),
			'created_at' => time(),
			'warnings' => $result->getWarnings()
		];
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, $change, self::CHANGE_TTL_SECONDS);

		return [
			'ok' => true,
			'applicable' => true,
			'change_id' => $changeId,
			'plan' => $plan,
			'base_head' => $baseHead,
			'operation_count' => count($operations),
			'warnings' => $result->getWarnings()
		];
	}

	private function parseBlockedAnalysis(string $text): string {
		$text = trim($text);
		if ($text === '') {
			throw new RuntimeException('MissionBay analysis ended without submitting an apply plan or blocker.');
		}
		$lines = preg_split('/\R/', $text, 2);
		$marker = strtoupper(trim((string)($lines[0] ?? '')));
		$detail = trim((string)($lines[1] ?? ''));
		if ($marker !== 'STATUS: BLOCKED' || $detail === '') {
			throw new RuntimeException('MissionBay analysis completed without calling evolution_apply_plan. Implementable changes must be submitted through that approval-bound plan tool; blockers must begin with STATUS: BLOCKED.');
		}
		return $detail;
	}

	/** @param array<int,array<string,mixed>> $operations */
	private function formatPlan(string $summary, array $operations): string {
		$lines = [$summary, '', 'Operations:'];
		foreach ($operations as $index => $operation) {
			$action = strtoupper((string)$operation['action']);
			$path = (string)$operation['path'];
			$reason = trim((string)($operation['reason'] ?? ''));
			$line = ($index + 1) . '. ' . $action . ' ' . $path;
			if ($action === 'WRITE') {
				$content = (string)($operation['content'] ?? '');
				$line .= ' (' . strlen($content) . ' bytes, sha256 ' . substr(hash('sha256', $content), 0, 16) . '…)';
			}
			if ($reason !== '') {
				$line .= "\n   " . $reason;
			}
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/** @return ?array<string,mixed> */
	private function findApplyPlanExecution(AgentExecutionResult $result): ?array {
		$execution = $result->getAgentResult()?->getState()->getExecution();
		$calls = $execution?->getExecutedToolCalls() ?? [];
		$match = null;
		foreach ($calls as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$name = trim((string)($entry['tool'] ?? ''));
			if (!$this->isApplyPlanToolName($name)) {
				continue;
			}
			if (isset($entry['error'])) {
				$match = [
					'ok' => false,
					'error' => (string)$entry['error'],
					'entry' => $entry
				];
				continue;
			}
			$toolResult = is_array($entry['result'] ?? null) ? $entry['result'] : [];
			if (($toolResult['ok'] ?? false) !== true) {
				$match = [
					'ok' => false,
					'error' => trim((string)($toolResult['error'] ?? 'Evolution apply-plan tool returned an unsuccessful result.')),
					'entry' => $entry
				];
				continue;
			}
			$match = ['ok' => true, 'entry' => $entry, 'result' => $toolResult];
		}
		return $match;
	}

	private function isApplyPlanToolName(string $name): bool {
		$name = trim($name);
		return $name === self::APPLY_PLAN_TOOL || str_ends_with($name, '__' . self::APPLY_PLAN_TOOL);
	}

	/** @param array<string,mixed> $change @param array<string,mixed> $baseGitSnapshot @return array<string,mixed> */
	private function finalizeAppliedChange(
		IStateStore $state,
		array $change,
		string $changeId,
		AgentExecutionResult $result,
		array $baseGitSnapshot,
		string $agentWarning = ''
	): array {
		$baseHead = trim((string)($baseGitSnapshot['head'] ?? ''));
		$changedPaths = $this->configuration->isGitRequired() ? $this->workspace->getChangedPaths($baseHead) : [];
		if ($this->configuration->isGitRequired() && $changedPaths === []) {
			throw new RuntimeException('Approved Evolution plan executed without producing EvolutionWorkspace source changes.');
		}

		$validation = $this->validateAppliedChange($baseHead);
		if (($validation['ok'] ?? false) !== true) {
			$rollback = $this->rollbackFailedChange($baseGitSnapshot);
			$failed = array_merge($change, [
				'status' => 'failed',
				'apply_finished_at' => time(),
				'validation' => $validation,
				'rollback' => $rollback,
				'agent_output' => $this->extractAssistantTextBestEffort($result),
				'agent_warning' => $agentWarning,
				'warnings' => $result->getWarnings(),
				'resume_handle' => '',
				'interaction_request_id' => ''
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
			'agent_output' => $this->extractAssistantTextBestEffort($result),
			'agent_warning' => $agentWarning,
			'warnings' => $result->getWarnings(),
			'resume_handle' => '',
			'interaction_request_id' => ''
		]);
		$state->set(self::CHANGE_KEY_PREFIX . $changeId, $done, self::CHANGE_TTL_SECONDS);

		$message = 'Approved EvolutionWorkspace plan was applied and validated.';
		if ($agentWarning !== '') {
			$message .= ' The source mutation succeeded; MissionBay reported a non-essential completion warning afterwards: ' . $agentWarning;
		}
		return [
			'ok' => true,
			'status' => 'applied',
			'message' => $message,
			'change_id' => $changeId,
			'changed_paths' => $changedPaths,
			'diff' => $diff,
			'validation' => $validation,
			'agent_output' => $this->extractAssistantTextBestEffort($result),
			'agent_warning' => $agentWarning,
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
			'interaction_request_id' => ''
		]), self::CHANGE_TTL_SECONDS);
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

	/** @param array<string,mixed> $change */
	private function assertCurrentChangeFormat(array $change): void {
		if ((int)($change['format_version'] ?? 0) === self::CHANGE_FORMAT_VERSION) {
			return;
		}
		throw new RuntimeException('This Evolution change was analyzed before the single-run plan/apply format. Re-run Analyze change before Apply.');
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
			$message = 'MissionBay agent ' . $phase . ' failed';
			if ($failureCode !== '') {
				$message .= ' [' . $failureCode . ']';
			}
			$message .= ': ' . $failureMessage;
			$detail = $this->formatFailureDetail($failureDetail);
			if ($detail !== '') {
				$message .= "\nDetail: " . $detail;
			}
			throw new RuntimeException($message);
		}

		$output = $result->getOutput();
		$assistant = is_array($output['assistant'] ?? null) ? $output['assistant'] : [];
		$error = $assistant['error'] ?? ($output['error'] ?? null);
		if (is_scalar($error) && trim((string)$error) !== '') {
			throw new RuntimeException('MissionBay agent ' . $phase . ' failed: ' . trim((string)$error));
		}
		$warning = is_scalar($assistant['warning'] ?? null) ? trim((string)$assistant['warning']) : '';
		if ($warning !== '') {
			$status = is_scalar($assistant['status'] ?? null)
				? trim((string)$assistant['status'])
				: ($agentResult?->getStatus() ?? 'unknown');
			$message = 'MissionBay agent ' . $phase . ' did not produce a complete final response [' . $warning . ']. Status: ' . ($status !== '' ? $status : 'unknown') . '.';
			$partialDetail = $this->getPartialResponseDetail($agentResult);
			if ($partialDetail !== '') {
				$message .= ' Detail: ' . $partialDetail;
			}
			throw new RuntimeException($message);
		}
		if ($agentResult !== null && !$agentResult->isCompleted()) {
			throw new RuntimeException('MissionBay agent ' . $phase . ' ended with non-terminal status: ' . $agentResult->getStatus() . '.');
		}
	}

	private function getAgentCompletionWarning(AgentExecutionResult $result): string {
		$agentResult = $result->getAgentResult();
		if ($agentResult?->hasFailure()) {
			$resultState = $agentResult->getState()->getResult();
			$code = trim((string)($resultState?->getFailureCode() ?? ''));
			$message = trim((string)($resultState?->getFailureMessage() ?? ''));
			$detail = $this->formatFailureDetail($resultState?->getFailureDetail() ?? []);
			$text = trim(($code !== '' ? '[' . $code . '] ' : '') . $message);
			if ($detail !== '') {
				$text .= ($text !== '' ? ' ' : '') . $detail;
			}
			return trim($text);
		}
		$output = $result->getOutput();
		$assistant = is_array($output['assistant'] ?? null) ? $output['assistant'] : [];
		$warning = is_scalar($assistant['warning'] ?? null) ? trim((string)$assistant['warning']) : '';
		return $warning;
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
			$error = trim((string)($metadata['error_message'] ?? $metadata['error'] ?? ''));
			if ($error === '') {
				continue;
			}
			$parts = ['stage=' . $entry->getStageId()];
			if (isset($metadata['iteration'])) {
				$parts[] = 'iteration=' . (string)$metadata['iteration'];
			}
			if (isset($metadata['error_type'])) {
				$parts[] = 'error_type=' . (string)$metadata['error_type'];
			}
			$parts[] = 'error=' . $error;
			return implode(', ', $parts);
		}
		return '';
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
		return is_string($json) && $json !== '{}' && $json !== 'null' ? $json : '';
	}

	private function extractAssistantText(AgentExecutionResult $result): string {
		$this->assertSuccessfulAgentResult($result, 'execution');
		$text = $this->extractAssistantTextBestEffort($result);
		if ($text === '') {
			$status = $result->getAgentResult()?->getStatus() ?? 'unknown';
			throw new RuntimeException('MissionBay agent returned no assistant message. Status: ' . $status . '.');
		}
		return $text;
	}

	private function extractAssistantTextBestEffort(AgentExecutionResult $result): string {
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
		return $content !== '' ? trim($content) : '';
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
