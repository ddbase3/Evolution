<?php declare(strict_types=1);

namespace Evolution\Display;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Evolution\Service\EvolutionAgentService;
use Evolution\Service\EvolutionHealthService;
use JsonException;
use Throwable;

final class EvolutionAction implements IOutput {

	public function __construct(
		private readonly IRequest $request,
		private readonly EvolutionHealthService $healthService,
		private readonly EvolutionAgentService $agentService
	) {}

	public static function getName(): string {
		return 'evolutionaction';
	}

	public function getOutput(string $out = 'json', bool $final = false): string {
		if ($out !== 'json') {
			return '';
		}

		try {
			$data = $this->request->getJsonBody();
			if ($data === []) {
				$data = $this->request->allRequest();
			}
			$action = strtolower(trim((string)($data['action'] ?? '')));

			$result = match($action) {
				'health' => $this->healthService->check(),
				'llm_test' => $this->agentService->testLlm(),
				'agent_test' => $this->agentService->testAgent(),
				'analyze' => $this->agentService->analyze((string)($data['prompt'] ?? '')),
				'apply' => $this->agentService->apply((string)($data['change_id'] ?? '')),
				'approve_apply' => $this->agentService->approveApply(
					(string)($data['change_id'] ?? ''),
					(string)($data['resume_handle'] ?? '')
				),
				default => [
					'ok' => false,
					'message' => 'Unknown Evolution action: ' . ($action !== '' ? $action : '(empty)')
				]
			};

			return $this->encode($result);
		} catch (Throwable $e) {
			return $this->encode([
				'ok' => false,
				'message' => $e->getMessage(),
				'type' => $e::class
			]);
		}
	}

	private function encode(array $data): string {
		try {
			return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return json_encode([
				'ok' => false,
				'message' => 'Unable to encode Evolution response: ' . $e->getMessage()
			], JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"message":"Unable to encode Evolution response."}';
		}
	}
}
