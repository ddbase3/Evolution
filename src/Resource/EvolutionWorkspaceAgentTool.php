<?php declare(strict_types=1);

namespace Evolution\Resource;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IOutputSchemaProvider;
use Evolution\Service\EvolutionDatabaseInspector;
use Evolution\Service\EvolutionWorkspaceService;
use InvalidArgumentException;
use MissionBay\Api\IAgentTool;
use MissionBay\Resource\AbstractAgentResource;
use Throwable;

final class EvolutionWorkspaceAgentTool extends AbstractAgentResource implements IAgentTool, IOutputSchemaProvider {

	public function __construct(
		private readonly EvolutionWorkspaceService $workspace,
		private readonly EvolutionDatabaseInspector $databaseInspector,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'evolutionworkspaceagenttool';
	}

	public function getDescription(): string {
		return 'Reads the current BASE3 application and submits one complete approval-bound change plan for plugin/EvolutionWorkspace.';
	}

	public function getToolDefinitions(): array {
		return [
			$this->readDefinition('evolution_workspace_info', 'Evolution Workspace Info', 'Returns the BASE3 application root, the dedicated writable EvolutionWorkspace plugin, Git status and discovered plugins.', []),
			$this->readDefinition('evolution_list_files', 'List Application Files', 'Lists files and directories below an application-relative path.', [
				'path' => ['type' => 'string', 'description' => 'Application-relative path. Empty means application root.'],
				'max_depth' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 12],
				'max_files' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000]
			]),
			$this->readDefinition('evolution_read_file', 'Read Application File', 'Reads a bounded line range from one text file. Use search-result line numbers and request additional ranges only when needed.', [
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Application-relative file path.'],
				'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'First line to return. Default: 1.'],
				'max_lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1200, 'description' => 'Maximum lines to return. Default: 160.']
			], ['path']),
			$this->readDefinition('evolution_search_source', 'Search Application Source', 'Searches application text files for a literal case-insensitive string. Search narrowly before listing large trees.', [
				'query' => ['type' => 'string', 'minLength' => 1],
				'path' => ['type' => 'string', 'description' => 'Optional application-relative search root.'],
				'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250]
			], ['query']),
			$this->readDefinition('evolution_database_schema', 'Inspect Database Schema', 'Lists database tables or describes columns and indexes for one table. Use only when the requested change depends on persisted data. It never executes arbitrary SQL.', [
				'table' => ['type' => 'string', 'description' => 'Optional exact table name. Empty lists all tables.']
			]),
			$this->readDefinition('evolution_git_diff', 'EvolutionWorkspace Git Diff', 'Returns the current Git diff and untracked file list for plugin/EvolutionWorkspace only.', []),
			$this->readDefinition('evolution_report_blocker', 'Report Evolution Planning Blocker', 'Reports an exact blocker that prevents a safe EvolutionWorkspace implementation. Use only when the requested change cannot be implemented safely after inspecting the required source and contracts.', [
				'reason' => ['type' => 'string', 'minLength' => 1, 'description' => 'Concise exact blocker reason.']
			], ['reason']),
			$this->mutationDefinition(
				'evolution_apply_plan',
				'Apply EvolutionWorkspace Plan',
				'Call this exactly once after analysis is complete. The arguments are the final concrete change plan. MissionBay pauses before execution so the host can show the plan to the user. After explicit approval, the exact stored operations are executed without asking the model to plan again.',
				[
					'summary' => [
						'type' => 'string',
						'minLength' => 1,
						'description' => 'Concise description of the complete requested change.'
					],
					'operations' => [
						'type' => 'array',
						'minItems' => 1,
						'maxItems' => 50,
						'description' => 'Complete ordered source mutation plan. Each target path may occur only once.',
						'items' => [
							'type' => 'object',
							'properties' => [
								'action' => [
									'type' => 'string',
									'enum' => ['write', 'delete']
								],
								'path' => [
									'type' => 'string',
									'minLength' => 1,
									'description' => 'Application-relative path below plugin/EvolutionWorkspace/.'
								],
								'content' => [
									'type' => 'string',
									'description' => 'Complete target file content. Required for write operations.'
								],
								'recursive' => [
									'type' => 'boolean',
									'description' => 'For delete operations, set true only when a non-empty directory must be removed.'
								],
								'reason' => [
									'type' => 'string',
									'description' => 'Short reason for this exact file operation.'
								]
							],
							'required' => ['action', 'path']
						]
					]
				],
				['summary', 'operations']
			)
		];
	}

	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		try {
			return match($name) {
				'evolution_workspace_info' => $this->workspace->getWorkspaceInfo(),
				'evolution_list_files' => $this->workspace->listFiles(
					(string)($arguments['path'] ?? ''),
					(int)($arguments['max_depth'] ?? 2),
					(int)($arguments['max_files'] ?? 120)
				),
				'evolution_read_file' => $this->workspace->readFileRange(
					(string)($arguments['path'] ?? ''),
					(int)($arguments['start_line'] ?? 1),
					(int)($arguments['max_lines'] ?? 160)
				),
				'evolution_search_source' => $this->workspace->searchSource(
					(string)($arguments['query'] ?? ''),
					(string)($arguments['path'] ?? ''),
					(int)($arguments['max_results'] ?? 20)
				),
				'evolution_database_schema' => $this->databaseInspector->inspect(
					isset($arguments['table']) ? (string)$arguments['table'] : null
				),
				'evolution_git_diff' => ['diff' => $this->workspace->getGitDiff()],
				'evolution_report_blocker' => $this->reportBlocker((string)($arguments['reason'] ?? '')),
				'evolution_apply_plan' => $this->workspace->applyPlan(
					is_array($arguments['operations'] ?? null) ? $arguments['operations'] : []
				),
				default => throw new InvalidArgumentException('Unsupported Evolution tool: ' . $name)
			};
		} catch (Throwable $e) {
			return [
				'ok' => false,
				'error' => $e->getMessage(),
				'type' => $e::class
			];
		}
	}

	public function getOutputSchemas(): array {
		$schema = ['type' => 'object'];
		return [
			'evolution_workspace_info' => $schema,
			'evolution_list_files' => ['type' => 'array'],
			'evolution_read_file' => $schema,
			'evolution_search_source' => ['type' => 'array'],
			'evolution_database_schema' => $schema,
			'evolution_git_diff' => $schema,
			'evolution_report_blocker' => $schema,
			'evolution_apply_plan' => $schema
		];
	}


	/** @return array{ok:bool,blocked:bool,reason:string} */
	private function reportBlocker(string $reason): array {
		$reason = trim($reason);
		if ($reason === '') {
			throw new InvalidArgumentException('Evolution blocker reason must not be empty.');
		}
		return [
			'ok' => true,
			'blocked' => true,
			'reason' => $reason
		];
	}

	/** @param array<string,mixed> $properties @param array<int,string> $required */
	private function readDefinition(string $name, string $label, string $description, array $properties, array $required = []): array {
		return [
			'type' => 'function',
			'label' => $label,
			'category' => 'evolution',
			'tags' => ['evolution', 'source'],
			'priority' => 100,
			'readOnlyHint' => true,
			'mutation' => false,
			'requiresApproval' => false,
			'function' => [
				'name' => $name,
				'description' => $description,
				'parameters' => [
					'type' => 'object',
					'properties' => $properties !== [] ? $properties : new \stdClass(),
					'required' => $required
				]
			]
		];
	}

	/** @param array<string,mixed> $properties @param array<int,string> $required */
	private function mutationDefinition(string $name, string $label, string $description, array $properties, array $required): array {
		return [
			'type' => 'function',
			'label' => $label,
			'category' => 'evolution',
			'tags' => ['evolution', 'source', 'mutation'],
			'priority' => 100,
			'readOnlyHint' => false,
			'mutation' => true,
			'requiresApproval' => true,
			'commitGuardRequired' => false,
			'sideEffectHint' => true,
			'destructiveHint' => false,
			'function' => [
				'name' => $name,
				'description' => $description,
				'parameters' => [
					'type' => 'object',
					'properties' => $properties,
					'required' => $required
				]
			]
		];
	}
}
