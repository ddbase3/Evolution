<?php declare(strict_types=1);

namespace Evolution\Service;

use AssistantFoundation\Api\IAgentModule;
use AssistantFoundation\Api\IAgentSuspensionRepository;
use AssistantFoundation\Api\IAiChatModel;
use Base3\Accesscontrol\Api\IAccesscontrol;
use Base3\Api\IClassMap;
use Base3\Api\IComponentResolver;
use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use Base3\Settings\Api\ISettingsStore;
use Base3\State\Api\IStateStore;
use Base3\Usermanager\Api\IUsermanager;
use MissionBay\Api\IAgentFlowCompiler;
use MissionBay\Api\IAgentResource;
use MissionBay\Api\IAgentTool;
use MissionBay\Dto\Orchestrator\AgentModelDecisionConfig;
use MissionBay\Orchestrator\Profile\AgentOrchestratorProfileRepository;
use MissionBay\Service\ConfiguredServiceRuntimeResolver;
use RuntimeException;
use Throwable;

final class EvolutionHealthService {

	private const AGENT_GROUP = 'agent';
	private const PRESET_GROUP = 'agent-component-preset';
	private const TOOL_PROFILE_GROUP = 'tool-profile';
	private const LLM_GROUP = 'service-llm';
	private const CONNECTION_GROUP = 'connection';

	public function __construct(
		private readonly EvolutionConfiguration $configuration,
		private readonly IContainer $container,
		private readonly EvolutionWorkspaceService $workspace
	) {}

	/** @return array<string,mixed> */
	public function check(): array {
		$checks = [];
		$analysisReady = true;
		$applyReady = true;

		$this->runCheck($checks, 'workspace', 'Workspace', function(): array {
			$configured = $this->configuration->getWorkspace();
			if ($configured === '') {
				throw new RuntimeException('Missing [evolution] workspace in cnf/config.ini.');
			}
			$real = realpath($configured);
			if (!is_string($real) || !is_dir($real)) {
				throw new RuntimeException('Configured Evolution workspace does not exist or is not a directory: ' . $configured);
			}
			if (!is_readable($real)) {
				throw new RuntimeException('Evolution workspace is not readable by the PHP process: ' . $real);
			}
			return ['message' => 'Workspace is readable.', 'details' => ['path' => $real]];
		}, $analysisReady, $applyReady, true, true);

		$this->runCheck($checks, 'write_scope', 'Write scope', function(): array {
			$workspace = realpath($this->configuration->getWorkspace());
			if (!is_string($workspace)) {
				throw new RuntimeException('Application root must be valid before write permissions can be checked.');
			}
			$target = $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->configuration->getWorkspacePluginPath());
			if (!is_dir($target)) {
				throw new RuntimeException('EvolutionWorkspace plugin directory does not exist: ' . $target);
			}
			if (!is_writable($target)) {
				throw new RuntimeException('EvolutionWorkspace plugin is not writable by the PHP process: ' . $target);
			}
			return [
				'message' => 'Writes are restricted to ' . $this->configuration->getWorkspacePluginPath() . '/.',
				'details' => ['path' => $target]
			];
		}, $analysisReady, $applyReady, false, true);
		$this->runCheck($checks, 'runtime_dirs', 'Runtime directories', function(): array {
			$errors = [];
			foreach ([
				'DIR_LOCAL' => defined('DIR_LOCAL') ? DIR_LOCAL : '',
				'DIR_TMP' => defined('DIR_TMP') ? DIR_TMP : ''
			] as $name => $path) {
				if ($path === '' || !is_dir($path)) {
					$errors[] = $name . ' does not point to an existing directory.';
					continue;
				}
				if (!is_writable($path)) {
					$errors[] = $name . ' is not writable: ' . $path;
				}
			}
			if ($errors !== []) {
				throw new RuntimeException(implode(' ', $errors));
			}
			return ['message' => 'Local and temporary runtime directories are writable.'];
		}, $analysisReady, $applyReady, false, true);

		$this->runCheck($checks, 'discovery_artifacts', 'Discovery artifacts', function(): array {
			$details = [];
			foreach ([
				'classmap' => DIR_TMP . 'classmap.php',
				'ctorcache' => DIR_TMP . 'ctorcache.php'
			] as $name => $file) {
				$size = is_file($file) ? filesize($file) : false;
				$details[$name] = [
					'file' => $file,
					'bytes' => is_int($size) ? $size : 0
				];
			}

			return [
				'message' => 'BASE3 classmap.php and ctorcache.php are regenerated together after every applied PHP change.',
				'details' => $details
			];
		}, $analysisReady, $applyReady, false, true);

		$this->runCheck($checks, 'git', 'EvolutionWorkspace Git', function(): array {
			$status = $this->workspace->getGitStatus();
			if (($status['available'] ?? false) !== true) {
				throw new RuntimeException('plugin/EvolutionWorkspace must be an independent Git repository before Apply: ' . (string)($status['error'] ?? 'unknown error'));
			}
			$root = trim((string)($status['root'] ?? ''));
			$gitDirectory = $root !== '' ? $root . DIRECTORY_SEPARATOR . '.git' : '';
			if ($gitDirectory !== '' && is_dir($gitDirectory) && !is_writable($gitDirectory)) {
				throw new RuntimeException('EvolutionWorkspace Git metadata is not writable by the PHP process: ' . $gitDirectory);
			}
			$head = trim((string)($status['head'] ?? ''));
			if ($head === '') {
				throw new RuntimeException('EvolutionWorkspace Git repository has no initial commit. Commit the accepted workspace baseline before Apply.');
			}
			return [
				'message' => ($status['clean'] ?? false) === true
					? 'EvolutionWorkspace Git repository is clean.'
					: 'EvolutionWorkspace Git repository contains uncommitted changes.',
				'status' => ($status['clean'] ?? false) === true ? 'ok' : 'warning',
				'details' => [
					'root' => (string)($status['root'] ?? ''),
					'clean' => (bool)($status['clean'] ?? false),
					'head' => $head,
					'branch' => (string)($status['branch'] ?? '')
				]
			];
		}, $analysisReady, $applyReady, false, $this->configuration->isGitRequired());
		if ($this->configuration->isGitRequired()) {
			$gitStatus = $checks['git']['details']['clean'] ?? null;
			if ($gitStatus === false) {
				$applyReady = false;
			}
		}

		$this->runCheck($checks, 'settings_path', 'Settings path', function(): array {
			$data = $this->configuration->getDataDirectory();
			if ($data === '') {
				throw new RuntimeException('Missing [directories] data in cnf/config.ini. Configure the data directory, normally <workspace>/local. JsonSettingsStore reads settings from <data>/cnf/settings.json. See plugin/Evolution/local/config.ini.example.');
			}
			$dataReal = realpath($data);
			if (!is_string($dataReal) || !is_dir($dataReal)) {
				throw new RuntimeException('Configured [directories] data directory does not exist: ' . $data);
			}

			$cnf = $dataReal . DIRECTORY_SEPARATOR . 'cnf';
			$file = $cnf . DIRECTORY_SEPARATOR . 'settings.json';
			if (is_file($file) && !is_readable($file)) {
				throw new RuntimeException('Settings file exists but is not readable by the PHP process: ' . $file);
			}

			if (is_dir($cnf) && !is_writable($cnf)) {
				return [
					'status' => 'warning',
					'message' => 'Settings directory is read-only for the PHP process. Existing settings can be used, but settings cannot be persisted from the runtime: ' . $cnf,
					'details' => ['file' => $file]
				];
			}

			if (!is_dir($cnf) && !is_writable($dataReal)) {
				return [
					'status' => 'warning',
					'message' => 'Settings directory does not exist and cannot be created by the PHP process. Create it manually before runtime settings need to be persisted: ' . $cnf,
					'details' => ['file' => $file]
				];
			}

			return [
				'message' => 'Settings storage path is available.',
				'details' => ['file' => $file]
			];
		}, $analysisReady, $applyReady, true, false);

		$settings = null;
		$this->runCheck($checks, 'settings_store', 'Settings store', function() use (&$settings): array {
			$settings = $this->requireService(ISettingsStore::class, ISettingsStore::class, 'Wire ISettingsStore in the Website project plugin.');
			$settings->reload();
			return ['message' => 'ISettingsStore loaded successfully.'];
		}, $analysisReady, $applyReady, true, true);

		$agent = [];
		$chatPreset = [];
		$llmServiceId = '';
		if ($settings instanceof ISettingsStore) {
			$this->runCheck($checks, 'agent_config', 'Evolution agent', function() use ($settings, &$agent, &$chatPreset, &$llmServiceId): array {
				$agentId = $this->configuration->getAgentId();
				$agent = $settings->get(self::AGENT_GROUP, $agentId, []);
				if ($agent === []) {
					throw new RuntimeException('Missing agent settings: agent/' . $agentId . '. Copy plugin/Evolution/local/settings.json.example to <directories.data>/cnf/settings.json and configure the LLM connection/model.');
				}
				if (($agent['enabled'] ?? true) === false) {
					throw new RuntimeException('Evolution agent is disabled: agent/' . $agentId);
				}
				$chatPresetId = strtolower(trim((string)($agent['chatmodel'] ?? '')));
				if ($chatPresetId === '') {
					throw new RuntimeException('Evolution agent has no chatmodel preset: agent/' . $agentId);
				}
				$chatPreset = $settings->get(self::PRESET_GROUP, $chatPresetId, []);
				if ($chatPreset === []) {
					throw new RuntimeException('Chat model preset not found: ' . self::PRESET_GROUP . '/' . $chatPresetId);
				}
				if (strtolower(trim((string)($chatPreset['type'] ?? ''))) !== 'configuredchatmodelagentresource') {
					throw new RuntimeException('Evolution chat model preset must use configuredchatmodelagentresource: ' . $chatPresetId);
				}
				$service = $chatPreset['config']['service'] ?? null;
				$llmServiceId = $this->extractFixedValue($service);
				if ($llmServiceId === '') {
					throw new RuntimeException('Evolution chat model preset must reference a configured LLM service through config.service.');
				}
				$profiles = is_array($agent['tool_profiles'] ?? null) ? $agent['tool_profiles'] : [];
				if (!in_array('evolution', array_map(static fn(mixed $value): string => strtolower(trim((string)$value)), $profiles), true)) {
					throw new RuntimeException('Evolution agent must include tool profile "evolution".');
				}
				$profile = $settings->get(self::TOOL_PROFILE_GROUP, 'evolution', []);
				if ($profile === []) {
					throw new RuntimeException('Missing tool profile: ' . self::TOOL_PROFILE_GROUP . '/evolution');
				}
				$tools = is_array($profile['tools'] ?? null) ? $profile['tools'] : [];
				if (!in_array('evolution-workspace', $tools, true)) {
					throw new RuntimeException('Evolution tool profile must include component preset "evolution-workspace".');
				}
				$toolPreset = $settings->get(self::PRESET_GROUP, 'evolution-workspace', []);
				if ($toolPreset === []) {
					throw new RuntimeException('Missing Evolution workspace component preset: ' . self::PRESET_GROUP . '/evolution-workspace');
				}
				if (strtolower(trim((string)($toolPreset['type'] ?? ''))) !== 'evolutionworkspaceagenttool') {
					throw new RuntimeException('Evolution workspace preset has wrong resource type. Expected evolutionworkspaceagenttool.');
				}
				$classMap = $this->requireService(IClassMap::class, IClassMap::class, 'IClassMap is not available.');
				$tool = $classMap->getInstanceByInterfaceName(IAgentResource::class, 'evolutionworkspaceagenttool');
				if (!$tool instanceof IAgentTool) {
					throw new RuntimeException('Evolution workspace resource does not implement IAgentTool.');
				}
				$mutationTools = [];
				$toolNames = [];
				foreach ($tool->getToolDefinitions() as $definition) {
					if (!is_array($definition)) {
						throw new RuntimeException('Evolution workspace tool returned an invalid function definition.');
					}
					$function = is_array($definition['function'] ?? null) ? $definition['function'] : [];
					$name = trim((string)($function['name'] ?? ''));
					if ($name !== '') {
						$toolNames[] = $name;
					}
					$parameters = $function['parameters'] ?? null;
					if (!is_array($parameters) || ($parameters['type'] ?? null) !== 'object') {
						throw new RuntimeException('Evolution tool has no object parameter schema: ' . ($name !== '' ? $name : '(unnamed)'));
					}
					if (($parameters['properties'] ?? null) === []) {
						throw new RuntimeException('Evolution tool has an OpenAI-incompatible empty properties array. Parameterless tools must serialize properties as an object: ' . $name);
					}
					if (($definition['mutation'] ?? false) === true) {
						if (($definition['requiresApproval'] ?? false) !== true) {
							throw new RuntimeException('Evolution mutation tool does not require MissionBay approval and will not be exposed: ' . $name);
						}
						$mutationTools[] = $name;
					}
				}
				if ($mutationTools !== ['evolution_apply_plan']) {
					throw new RuntimeException('Evolution must expose exactly one approval-bound mutation tool: evolution_apply_plan. Found: ' . implode(', ', $mutationTools));
				}
				if (!in_array('evolution_report_blocker', $toolNames, true)) {
					throw new RuntimeException('Evolution workspace resource must expose the read-only evolution_report_blocker planning outcome tool.');
				}
				$planningModuleClass = $classMap->getClassByInterfaceName(
					IAgentModule::class,
					EvolutionConfiguration::PLANNING_MODULE_IMPLEMENTATION
				);
				if (!is_string($planningModuleClass) || $planningModuleClass === '') {
					throw new RuntimeException('Evolution planning guard implementation is not discoverable: ' . EvolutionConfiguration::PLANNING_MODULE_IMPLEMENTATION);
				}
				$componentResolver = $this->requireService(
					IComponentResolver::class,
					IComponentResolver::class,
					'IComponentResolver is not available.'
				);
				$planningModule = $componentResolver->get(
					IAgentModule::class,
					EvolutionConfiguration::PLANNING_MODULE_COMPONENT
				);
				if (!$planningModule instanceof IAgentModule) {
					throw new RuntimeException('Evolution planning guard configured component does not resolve: ' . EvolutionConfiguration::PLANNING_MODULE_COMPONENT);
				}

				$orchestratorProfileId = strtolower(trim((string)($agent['orchestrator_profile'] ?? AgentOrchestratorProfileRepository::DEFAULT_PROFILE_ID)));
				$profileRepository = $this->requireService(
					AgentOrchestratorProfileRepository::class,
					AgentOrchestratorProfileRepository::class,
					'MissionBay AgentOrchestratorProfileRepository is not available.'
				);
				$orchestratorProfile = $profileRepository->getProfile($orchestratorProfileId);
				$maxToolLoops = $orchestratorProfile->getMaxToolLoops();
				$modelDecisionStrategy = $orchestratorProfile->getModelDecision()->getStrategy();
				$stageIds = $orchestratorProfile->getStageIds();
				$contextCompaction = in_array('context-compaction', $stageIds, true);
				$deliberatePlanning = $orchestratorProfile->isDeliberatePlanningEnabled();
				$lowLoopLimit = $maxToolLoops < 16;
				$guardedDecision = $modelDecisionStrategy === AgentModelDecisionConfig::STRATEGY_AI_GUARDED;

				$message = 'Agent, chat model preset, Evolution tool profile, planning guard module, single approval-bound apply-plan tool and orchestrator profile are configured.';
				$status = 'ok';
				if ($lowLoopLimit) {
					$status = 'warning';
					$message = 'Agent configuration is valid, but orchestrator profile "' . $orchestratorProfileId . '" allows only ' . $maxToolLoops . ' tool loops. Evolution recommends at least 16 loops; the bundled profile uses 32.';
				}
				elseif (!$guardedDecision) {
					$status = 'warning';
					$message = 'Agent configuration is valid, but Evolution recommends MissionBay ai-guarded-model-decision together with the Evolution planning guard module.';
				}
				elseif (!$contextCompaction) {
					$status = 'warning';
					$message = 'Agent configuration is valid, but the effective MissionBay pipeline has context-compaction disabled. Evolution repository analysis should keep this existing MissionBay stage enabled.';
				}

				return [
					'status' => $status,
					'message' => $message,
					'details' => [
						'agent' => $agentId,
						'chatmodel' => $chatPresetId,
						'llm_service' => $llmServiceId,
						'orchestrator_profile' => $orchestratorProfileId,
						'max_tool_loops' => $maxToolLoops,
						'model_decision' => $modelDecisionStrategy,
						'deliberate_planning' => $deliberatePlanning,
						'context_compaction' => $contextCompaction,
						'planning_guard_component' => EvolutionConfiguration::PLANNING_MODULE_COMPONENT,
						'planning_guard_implementation' => EvolutionConfiguration::PLANNING_MODULE_IMPLEMENTATION,
						'stage_ids' => $stageIds
					]
				];
			}, $analysisReady, $applyReady, true, true);
		}

		if ($settings instanceof ISettingsStore && $llmServiceId !== '') {
			$this->runCheck($checks, 'llm_config', 'LLM service', function() use ($settings, $llmServiceId): array {
				$serviceSettings = $settings->get(self::LLM_GROUP, $llmServiceId, []);
				if ($serviceSettings === []) {
					throw new RuntimeException('Configured LLM service not found: ' . self::LLM_GROUP . '/' . $llmServiceId);
				}

				$driver = strtolower(trim((string)($serviceSettings['driver'] ?? '')));
				$modelName = trim((string)($serviceSettings['model'] ?? ''));
				if ($modelName === '') {
					throw new RuntimeException('Configured LLM service has no model: ' . self::LLM_GROUP . '/' . $llmServiceId . '. For the bundled OpenAI example use model "gpt-4.1".');
				}

				$connectionId = strtolower(trim((string)($serviceSettings['connection'] ?? '')));
				if ($connectionId === '') {
					throw new RuntimeException('Configured LLM service has no connection id: ' . self::LLM_GROUP . '/' . $llmServiceId);
				}
				$connectionSettings = $settings->get(self::CONNECTION_GROUP, $connectionId, []);
				if ($connectionSettings === []) {
					throw new RuntimeException('Configured LLM connection not found: ' . self::CONNECTION_GROUP . '/' . $connectionId);
				}
				$baseUrl = trim((string)($connectionSettings['baseUrl'] ?? ''));
				if ($baseUrl === '') {
					$suggestion = $driver === 'openai-chat'
						? ' Set connection/' . $connectionId . '.baseUrl to "https://api.openai.com".'
						: ' Set connection/' . $connectionId . '.baseUrl to the root URL of the OpenAI-compatible endpoint.';
					throw new RuntimeException('Configured LLM connection has no base URL: ' . self::CONNECTION_GROUP . '/' . $connectionId . '.' . $suggestion);
				}

				$resolver = $this->requireService(
					ConfiguredServiceRuntimeResolver::class,
					ConfiguredServiceRuntimeResolver::class,
					'MissionBay ConfiguredServiceRuntimeResolver is not available.'
				);
				$model = $resolver->resolve(self::LLM_GROUP, $llmServiceId, 'llm', 'llm', IAiChatModel::class);
				if (!$model instanceof IAiChatModel) {
					throw new RuntimeException('Configured LLM service did not resolve to IAiChatModel.');
				}
				return [
					'message' => 'LLM service, connection, driver, model and secret resolve successfully.',
					'details' => ['service' => $llmServiceId, 'model' => (string)($serviceSettings['model'] ?? ''), 'driver' => (string)($serviceSettings['driver'] ?? '')]
				];
			}, $analysisReady, $applyReady, true, true);
		}

		if ($agent !== []) {
			$this->runCheck($checks, 'agent_compile', 'Agent compilation', function() use ($agent): array {
				$compiler = $this->requireService(IAgentFlowCompiler::class, IAgentFlowCompiler::class, 'MissionBay IAgentFlowCompiler is not available.');
				$compilation = $compiler->compile($this->configuration->prepareAgentSettings($agent));
				return [
					'message' => 'MissionBay agent flow compiles successfully.',
					'details' => ['warnings' => $compilation->getWarnings()]
				];
			}, $analysisReady, $applyReady, true, true);
		}

		$this->runCheck($checks, 'database', 'Database', function(): array {
			if (!class_exists('mysqli')) {
				throw new RuntimeException('PHP mysqli extension is not available. Install/enable mysqli for the configured MysqlDatabase service.');
			}
			$database = $this->requireService(IDatabase::class, IDatabase::class, 'Wire IDatabase to MysqlDatabase in the Website project plugin.');
			$database->connect();
			if (!$database->connected()) {
				throw new RuntimeException('Database connection failed. Check [database] host, user, pass and name.');
			}
			$value = $database->scalarQuery('SELECT 1');
			if ((string)$value !== '1') {
				throw new RuntimeException('Database is connected but SELECT 1 did not return the expected result.');
			}
			$database->listQuery('SHOW TABLES');
			return ['message' => 'Database connection and schema read access are available.'];
		}, $analysisReady, $applyReady, false, false);

		$this->runCheck($checks, 'state_store', 'State store', function(): array {
			$state = $this->requireService(IStateStore::class, IStateStore::class, 'Wire IStateStore in the Website project plugin.');
			$key = 'evolution.health.' . bin2hex(random_bytes(8));
			$value = ['probe' => bin2hex(random_bytes(8)), 'time' => time()];
			try {
				$state->set($key, $value, 60);
				$read = $state->get($key);
				if ($read !== $value) {
					throw new RuntimeException('IStateStore write/read probe returned different data.');
				}
			} finally {
				try {
					$state->delete($key);
				} catch (Throwable) {
				}
			}
			return ['message' => 'IStateStore write/read/delete probe succeeded.'];
		}, $analysisReady, $applyReady, true, true);

		$this->runCheck($checks, 'agent_suspension', 'Agent approval persistence', function(): array {
			$repository = $this->requireService(
				IAgentSuspensionRepository::class,
				IAgentSuspensionRepository::class,
				'Wire IAgentSuspensionRepository to a persistent implementation. The installed AssistantRuntime StateStoreAgentSuspensionRepository can use the configured IStateStore.'
			);
			$repository->findPending('evolution-health-' . bin2hex(random_bytes(8)));
			return ['message' => 'Persistent MissionBay approval suspension/resume storage is available.'];
		}, $analysisReady, $applyReady, false, true);

		$this->runCheck($checks, 'system_prompt', 'System prompt', function(): array {
			$file = $this->configuration->getSystemPromptFile();
			if (!is_file($file) || !is_readable($file)) {
				throw new RuntimeException('Evolution system prompt is missing or not readable: ' . $file);
			}
			$content = file_get_contents($file);
			if (!is_string($content) || trim($content) === '') {
				throw new RuntimeException('Evolution system prompt is empty: ' . $file);
			}
			return ['message' => 'Evolution system prompt is available.', 'details' => ['file' => $file]];
		}, $analysisReady, $applyReady, true, true);

		$this->runCheck($checks, 'logger', 'Logger', function(): array {
			$this->requireService(ILogger::class, ILogger::class, 'Wire ILogger in the Website project plugin.');
			return ['message' => 'ILogger is registered.'];
		}, $analysisReady, $applyReady, false, false);

		$this->runCheck($checks, 'php_cli', 'PHP CLI', function(): array {
			$binary = $this->workspace->getPhpCliBinary();
			return [
				'message' => 'PHP CLI executable is available for linting and local test execution.',
				'details' => [
					'binary' => $binary,
					'runtime_binary' => PHP_BINARY,
					'runtime_sapi' => PHP_SAPI
				]
			];
		}, $analysisReady, $applyReady, false, true);

		$this->runCheck($checks, 'phpunit', 'Tests', function(): array {
			if (!$this->workspace->hasPhpUnit()) {
				return ['status' => 'warning', 'message' => 'PHPUnit executable was not found. Apply can still validate PHP syntax and ClassMap, but project tests will be skipped.'];
			}
			return ['message' => 'PHPUnit executable is available.'];
		}, $analysisReady, $applyReady, false, false);

		$this->runCheck($checks, 'access', 'Access protection', function(): array {
			$userId = null;
			if ($this->container->has(IAccesscontrol::class)) {
				$access = $this->container->get(IAccesscontrol::class);
				if ($access instanceof IAccesscontrol) {
					$userId = $access->getUserId();
				}
			}
			$user = null;
			if ($this->container->has(IUsermanager::class)) {
				$manager = $this->container->get(IUsermanager::class);
				if ($manager instanceof IUsermanager) {
					$user = $manager->getUser();
				}
			}
			if ($userId === null && $user === null) {
				return [
					'status' => 'warning',
					'message' => 'No authenticated user is visible to Evolution. The mutation endpoint is therefore not protected by BASE3 user identity in this prototype.'
				];
			}
			return ['message' => 'Authenticated user context is available.'];
		}, $analysisReady, $applyReady, false, false);

		return [
			'ok' => $analysisReady,
			'analysis_ready' => $analysisReady,
			'apply_ready' => $applyReady,
			'checks' => $checks,
			'agent_id' => $this->configuration->getAgentId(),
			'llm_service_id' => $llmServiceId
		];
	}

	/** @return array<string,mixed> */
	public function testLlm(): array {
		$health = $this->check();
		if (($health['analysis_ready'] ?? false) !== true) {
			return [
				'ok' => false,
				'message' => 'LLM test is unavailable until the required Evolution configuration checks pass.'
			];
		}

		$serviceId = trim((string)($health['llm_service_id'] ?? ''));
		$settingsStore = $this->requireService(ISettingsStore::class, ISettingsStore::class, 'ISettingsStore is not available.');
		$settings = $settingsStore->get(self::LLM_GROUP, $serviceId, []);
		if ($settings === []) {
			return ['ok' => false, 'message' => 'Configured LLM service settings are missing: ' . $serviceId];
		}

		$classMap = $this->requireService(IClassMap::class, IClassMap::class, 'IClassMap is not available.');
		$testerClass = 'MissionBay\\Service\\ConfiguredServiceTestService';
		$tester = $classMap->instantiate($testerClass);
		if (!$tester instanceof \AssistantFoundation\Api\IAiServiceTester) {
			return ['ok' => false, 'message' => 'MissionBay configured service tester could not be instantiated.'];
		}

		return $tester->test(['id' => $serviceId, 'settings' => $settings]);
	}

	/**
	 * @param array<string,array<string,mixed>> $checks
	 */
	private function runCheck(
		array &$checks,
		string $id,
		string $label,
		callable $check,
		bool &$analysisReady,
		bool &$applyReady,
		bool $requiredForAnalysis,
		bool $requiredForApply
	): void {
		try {
			$result = $check();
			$status = (string)($result['status'] ?? 'ok');
			$checks[$id] = [
				'id' => $id,
				'label' => $label,
				'status' => $status,
				'message' => (string)($result['message'] ?? 'Ok'),
				'details' => is_array($result['details'] ?? null) ? $result['details'] : []
			];
			if ($status === 'error') {
				if ($requiredForAnalysis) $analysisReady = false;
				if ($requiredForApply) $applyReady = false;
			}
		} catch (Throwable $e) {
			$checks[$id] = [
				'id' => $id,
				'label' => $label,
				'status' => 'error',
				'message' => $e->getMessage(),
				'details' => ['type' => $e::class]
			];
			if ($requiredForAnalysis) $analysisReady = false;
			if ($requiredForApply) $applyReady = false;
		}
	}

	private function extractFixedValue(mixed $value): string {
		if (is_string($value) || is_int($value)) {
			return strtolower(trim((string)$value));
		}
		if (!is_array($value)) {
			return '';
		}
		$mode = strtolower(trim((string)($value['mode'] ?? '')));
		if ($mode === '' || $mode === 'fixed') {
			return strtolower(trim((string)($value['value'] ?? '')));
		}
		return '';
	}

	/** @template T of object @param class-string<T> $expected @return T */
	private function requireService(string $name, string $expected, string $message): object {
		if (!$this->container->has($name)) {
			throw new RuntimeException($message);
		}
		$service = $this->container->get($name);
		if (!is_object($service) || !$service instanceof $expected) {
			throw new RuntimeException($message . ' Registered value: ' . get_debug_type($service));
		}
		return $service;
	}
}
