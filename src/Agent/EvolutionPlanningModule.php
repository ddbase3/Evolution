<?php declare(strict_types=1);

namespace Evolution\Agent;

use AssistantFoundation\Api\IAgentContext;
use AssistantFoundation\Api\IAgentModule;
use AssistantFoundation\Dto\AgentModuleActivation;
use AssistantFoundation\Dto\AgentModuleManifest;
use AssistantFoundation\Dto\AgentStageMount;
use AssistantFoundation\Dto\AgentStageSlot;

final class EvolutionPlanningModule implements IAgentModule {

	public static function getName(): string {
		return 'evolutionplanningmodule';
	}

	public function id(): string {
		return 'evolution-planning';
	}

	public function manifest(): AgentModuleManifest {
		return new AgentModuleManifest(
			'evolution-planning',
			'Evolution planning guard',
			'Keeps Evolution planning inside the active MissionBay run until a concrete apply plan or exact blocker is submitted.',
			['evolution', 'planning']
		);
	}

	public function activate(IAgentContext $context): AgentModuleActivation {
		if ((string)($context->getVar('evolution_mode') ?? '') !== 'plan') {
			return new AgentModuleActivation();
		}

		return new AgentModuleActivation(
			instructions: [
				'Evolution planning is complete only after evolution_apply_plan has been submitted for approval or evolution_report_blocker has reported an exact blocker. A textual plan or generic complete decision is not a valid planning completion.'
			],
			stages: [
				new AgentStageMount(
					AgentStageSlot::BEFORE_EXECUTION,
					new EvolutionPlanningGuardStage()
				)
			]
		);
	}
}
