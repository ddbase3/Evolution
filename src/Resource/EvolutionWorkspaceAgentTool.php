<?php declare(strict_types=1);

namespace Evolution\Resource;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IOutputSchemaProvider;
use Evolution\Service\EvolutionDatabaseInspector;
use Evolution\Service\EvolutionWorkspaceService;
use InvalidArgumentException;
use MissionBay\Api\IAgentTool;
use MissionBay\Resource\AbstractAgentResource;
use RuntimeException;
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
		return 'Inspects and, only during an approved Evolution Apply run, modifies the configured BASE3 workspace.';
	}

	public function getToolDefinitions(): array {
		return [
			$this->readDefinition('evolution_workspace_info', 'Evolution Workspace Info', 'Returns the configured workspace, write scope, Git status and discovered plugins.', []),
			$this->readDefinition('evolution_list_files', 'List Workspace Files', 'Lists files and directories below a workspace-relative path.', [
				'path' => ['type' => 'string', 'description' => 'Workspace-relative path. Empty means workspace root.'],
				'max_depth' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 12],
				'max_files' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000]
			]),
			$this->readDefinition('evolution_read_file', 'Read Workspace File', 'Reads one text file from the Evolution workspace.', [
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Workspace-relative file path.']
			], ['path']),
			$this->readDefinition('evolution_search_source', 'Search Workspace Source', 'Searches text source files for a literal case-insensitive string.', [
				'query' => ['type' => 'string', 'minLength' => 1],
				'path' => ['type' => 'string', 'description' => 'Optional workspace-relative search root.'],
				'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250]
			], ['query']),
			$this->readDefinition('evolution_database_schema', 'Inspect Database Schema', 'Lists database tables or describes columns and indexes for one table. It never executes arbitrary SQL.', [
				'table' => ['type' => 'string', 'description' => 'Optional exact table name. Empty lists all tables.']
			]),
			$this->readDefinition('evolution_git_diff', 'Evolution Git Diff', 'Returns the current Git diff and untracked file list for the Evolution workspace.', []),
			$this->readDefinition('evolution_php_lint', 'Validate Changed PHP', 'Runs PHP syntax validation for changed PHP files.', []),
			$this->mutationDefinition('evolution_write_file', 'Write Workspace File', 'Creates or replaces one text file inside the configured Evolution write scope.', [
				'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Workspace-relative target path.'],
				'content' => ['type' => 'string', 'description' => 'Complete target file content.']
			], ['path', 'content']),
			$this->mutationDefinition('evolution_delete_path', 'Delete Workspace Path', 'Deletes one file or directory inside the configured Evolution write scope.', [
				'path' => ['type' => 'string', 'minLength' => 1],
				'recursive' => ['type' => 'boolean', 'description' => 'Required true for non-empty directories.']
			], ['path']),
			$this->mutationDefinition('evolution_refresh_classmap', 'Regenerate BASE3 ClassMap', 'Regenerates the existing BASE3 ClassMap and constructor cache.', [], []),
			$this->mutationDefinition('evolution_run_tests', 'Run Project Tests', 'Runs the project PHPUnit suite when PHPUnit is available.', [], [])
		];
	}

	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		try {
			return match($name) {
				'evolution_workspace_info' => $this->workspace->getWorkspaceInfo(),
				'evolution_list_files' => $this->workspace->listFiles(
					(string)($arguments['path'] ?? ''),
					(int)($arguments['max_depth'] ?? 3),
					(int)($arguments['max_files'] ?? 250)
				),
				'evolution_read_file' => [
					'path' => (string)($arguments['path'] ?? ''),
					'content' => $this->workspace->readFile((string)($arguments['path'] ?? ''))
				],
				'evolution_search_source' => $this->workspace->searchSource(
					(string)($arguments['query'] ?? ''),
					(string)($arguments['path'] ?? ''),
					(int)($arguments['max_results'] ?? 50)
				),
				'evolution_database_schema' => $this->databaseInspector->inspect(
					isset($arguments['table']) ? (string)$arguments['table'] : null
				),
				'evolution_git_diff' => ['diff' => $this->workspace->getGitDiff()],
				'evolution_php_lint' => $this->workspace->validateChangedPhp(),
				'evolution_write_file' => $this->applyOnly($context, fn() => $this->workspace->writeFile(
					(string)($arguments['path'] ?? ''),
					(string)($arguments['content'] ?? '')
				)),
				'evolution_delete_path' => $this->applyOnly($context, fn() => $this->workspace->deletePath(
					(string)($arguments['path'] ?? ''),
					(bool)($arguments['recursive'] ?? false)
				)),
				'evolution_refresh_classmap' => $this->applyOnly($context, fn() => $this->workspace->refreshClassMap()),
				'evolution_run_tests' => $this->applyOnly($context, fn() => $this->workspace->runTests()),
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
			'evolution_php_lint' => $schema,
			'evolution_write_file' => $schema,
			'evolution_delete_path' => $schema,
			'evolution_refresh_classmap' => $schema,
			'evolution_run_tests' => $schema
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
					'properties' => $properties !== [] ? $properties : new \stdClass(),
					'required' => $required
				]
			]
		];
	}

	private function applyOnly(IAgentContext $context, callable $operation): mixed {
		if ($context->getVar('evolution_mode') !== 'apply') {
			throw new RuntimeException('Evolution source mutation is disabled during analysis. Use the explicit Apply action first.');
		}
		return $operation();
	}
}
