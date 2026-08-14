<?php declare(strict_types=1);

namespace Evolution\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IMvcView;
use Base3\Api\IOutput;
use Evolution\Service\EvolutionHealthService;

final class EvolutionDisplay implements IOutput {

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly EvolutionHealthService $healthService
	) {}

	public static function getName(): string {
		return 'evolutiondisplay';
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if ($out !== 'html') {
			return '';
		}

		$this->view->setPath(DIR_PLUGIN . 'Evolution');
		$this->view->setTemplate('Display/EvolutionDisplay.php');
		$this->view->assign('health', $this->healthService->check());
		$this->view->assign('action_url', 'index.php?name=evolutionaction&out=json');
		$this->view->assign('resolve', fn(string $path): string => $this->assetResolver->resolve($path));

		return $this->view->loadTemplate();
	}
}
