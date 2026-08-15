<?php declare(strict_types=1);

namespace Evolution\Agent;

use AssistantFoundation\Api\IAgentContext;
use AssistantFoundation\Api\IAgentStage;
use AssistantFoundation\Dto\AgentStageResult;
use MissionBay\Orchestrator\Stage\AgentToolLoopContextKeys;

final class EvolutionPlanningGuardStage implements IAgentStage {

	private const APPLY_PLAN_TOOL = 'evolution_apply_plan';
	private const BLOCKER_TOOL = 'evolution_report_blocker';

	public static function getName(): string {
		return 'evolutionplanningguardstage';
	}

	public function id(): string {
		return self::getName();
	}

	public function name(): string {
		return 'evolution-planning-guard';
	}

	public function getDescription(): string {
		return 'Prevents an Evolution planning run from completing before it submits an approval-bound apply plan or reports an exact blocker.';
	}

	public function getAiUsage(): string {
		return IAgentStage::AI_USAGE_NONE;
	}

	public function supports(IAgentContext $context): bool {
		return (string)($context->getVar('evolution_mode') ?? '') === 'plan'
			&& $context->getVar(AgentToolLoopContextKeys::PHASE) === AgentToolLoopContextKeys::PHASE_FINAL
			&& $context->getVar(AgentToolLoopContextKeys::COMPLETED) === true
			&& (string)($context->getVar(AgentToolLoopContextKeys::FAILURE_CODE) ?? '') === ''
			&& (string)($context->getVar(AgentToolLoopContextKeys::FINAL_RESPONSE_MODE) ?? '') === AgentToolLoopContextKeys::FINAL_RESPONSE_COMPLETE;
	}

	public function process(IAgentContext $context): AgentStageResult {
		if ($this->hasSuccessfulToolExecution($context, self::BLOCKER_TOOL)) {
			return AgentStageResult::none([
				'evolution_planning_guard' => 'blocker_reported'
			]);
		}

		if ($this->hasExplicitTextBlocker($context)) {
			return AgentStageResult::none([
				'evolution_planning_guard' => 'text_blocker'
			]);
		}

		return AgentStageResult::patch([
			AgentToolLoopContextKeys::CONTINUATION_HINT => implode("\n", [
				'Evolution planning is not complete yet.',
				'Do not finish with a textual plan or a generic complete decision.',
				'If the requested change is implementable, call evolution_apply_plan now with the complete final mutation set.',
				'If an exact blocker prevents safe implementation, call evolution_report_blocker now with the concise blocker reason.'
			]),
			AgentToolLoopContextKeys::FINAL_ASSISTANT_MESSAGE => null,
			AgentToolLoopContextKeys::FINAL_OUTPUT_CONTENT => '',
			AgentToolLoopContextKeys::FINAL_OUTPUT_DELIVERY => AgentToolLoopContextKeys::FINAL_OUTPUT_DELIVERY_NONE,
			AgentToolLoopContextKeys::FINAL_RESPONSE_MODE => AgentToolLoopContextKeys::FINAL_RESPONSE_NONE,
			AgentToolLoopContextKeys::FINAL_RESPONSE_INSTRUCTION => '',
			AgentToolLoopContextKeys::PENDING_TOOL_CALLS => [],
			AgentToolLoopContextKeys::TERMINAL_EVIDENCE_READY => false,
			AgentToolLoopContextKeys::COMPLETED => false,
			AgentToolLoopContextKeys::PHASE => AgentToolLoopContextKeys::PHASE_MODEL
		], [
			'evolution_planning_guard' => 'continued'
		]);
	}

	private function hasSuccessfulToolExecution(IAgentContext $context, string $toolName): bool {
		$executions = $context->getVar(AgentToolLoopContextKeys::EXECUTED_TOOL_CALLS);
		if (!is_array($executions)) {
			return false;
		}

		foreach ($executions as $execution) {
			if (!is_array($execution)) {
				continue;
			}
			$name = trim((string)($execution['tool'] ?? ''));
			if (!$this->isToolName($name, $toolName) || isset($execution['error'])) {
				continue;
			}
			$result = is_array($execution['result'] ?? null) ? $execution['result'] : [];
			if (($result['ok'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}

	private function hasExplicitTextBlocker(IAgentContext $context): bool {
		$message = $context->getVar(AgentToolLoopContextKeys::FINAL_ASSISTANT_MESSAGE);
		$content = '';
		if (is_array($message)) {
			$content = trim((string)($message['content'] ?? ''));
		}
		if ($content === '') {
			$content = trim((string)($context->getVar(AgentToolLoopContextKeys::FINAL_OUTPUT_CONTENT) ?? ''));
		}
		if ($content === '') {
			return false;
		}

		$lines = preg_split('/\R/', $content, 2);
		return strtoupper(trim((string)($lines[0] ?? ''))) === 'STATUS: BLOCKED'
			&& trim((string)($lines[1] ?? '')) !== '';
	}

	private function isToolName(string $actual, string $expected): bool {
		return $actual === $expected || str_ends_with($actual, '__' . $expected);
	}
}
