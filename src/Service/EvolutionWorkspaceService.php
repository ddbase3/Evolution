<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Api\IClassMap;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class EvolutionWorkspaceService {

	private const MAX_READ_BYTES = 500000;
	private const MAX_WRITE_BYTES = 5000000;
	private const MAX_PROCESS_OUTPUT_BYTES = 500000;

	public function __construct(
		private readonly EvolutionConfiguration $configuration,
		private readonly IClassMap $classMap
	) {}

	/** @return array<string,mixed> */
	public function getWorkspaceInfo(): array {
		$workspace = $this->requireWorkspace();
		$git = $this->getGitStatus();

		return [
			'workspace' => $workspace,
			'plugin_path' => $workspace . DIRECTORY_SEPARATOR . 'plugin',
			'framework_write' => $this->configuration->isFrameworkWriteEnabled(),
			'git_required' => $this->configuration->isGitRequired(),
			'git' => $git,
			'plugins' => $this->classMap->getPlugins()
		];
	}

	/** @return array<int,string> */
	public function listFiles(string $relativePath = '', int $maxDepth = 3, int $maxFiles = 250): array {
		$path = $this->resolveExistingPath($relativePath, true);
		$maxDepth = max(0, min(12, $maxDepth));
		$maxFiles = max(1, min(2000, $maxFiles));
		$workspace = $this->requireWorkspace();
		$result = [];

		if (is_file($path) || is_link($path)) {
			return [$this->relativePath($path)];
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		$iterator->setMaxDepth($maxDepth);

		foreach ($iterator as $item) {
			$realPath = $item->getPathname();
			$relative = substr($realPath, strlen($workspace) + 1);
			$relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

			if ($this->isIgnoredPath($relative)) {
				continue;
			}

			$result[] = $relative . ($item->isDir() ? '/' : '');
			if (count($result) >= $maxFiles) {
				break;
			}
		}

		sort($result);
		return $result;
	}

	public function readFile(string $relativePath, int $maxBytes = self::MAX_READ_BYTES): string {
		$path = $this->resolveExistingPath($relativePath, false);
		if (!is_file($path)) {
			throw new RuntimeException('Path is not a file: ' . $relativePath);
		}

		$maxBytes = max(1, min(self::MAX_READ_BYTES, $maxBytes));
		$size = filesize($path);
		if (is_int($size) && $size > $maxBytes) {
			throw new RuntimeException('File exceeds read limit (' . $maxBytes . ' bytes): ' . $relativePath);
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException('Unable to read file: ' . $relativePath);
		}

		if (str_contains($content, "\0")) {
			throw new RuntimeException('Binary files cannot be read through the Evolution text tool: ' . $relativePath);
		}

		return $content;
	}

	/** @return array<int,array{file:string,line:int,text:string}> */
	public function searchSource(string $query, string $relativePath = '', int $maxResults = 50): array {
		$query = trim($query);
		if ($query === '') {
			throw new RuntimeException('Search query must not be empty.');
		}

		$path = $this->resolveExistingPath($relativePath, true);
		$maxResults = max(1, min(250, $maxResults));
		$files = [];

		if (is_file($path)) {
			$files[] = $path;
		} else {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $item) {
				if (!$item->isFile() || $item->isLink()) {
					continue;
				}
				$relative = $this->relativePath($item->getPathname());
				if ($this->isIgnoredPath($relative)) {
					continue;
				}
				$size = $item->getSize();
				if ($size > self::MAX_READ_BYTES) {
					continue;
				}
				$files[] = $item->getPathname();
			}
		}

		$result = [];
		foreach ($files as $file) {
			$content = file_get_contents($file);
			if ($content === false || str_contains($content, "\0")) {
				continue;
			}
			$lines = preg_split('/\R/u', $content) ?: [];
			foreach ($lines as $index => $line) {
				if (stripos($line, $query) === false) {
					continue;
				}
				$result[] = [
					'file' => $this->relativePath($file),
					'line' => $index + 1,
					'text' => $this->limitText(trim($line), 500)
				];
				if (count($result) >= $maxResults) {
					return $result;
				}
			}
		}

		return $result;
	}

	/** @return array<string,mixed> */
	public function writeFile(string $relativePath, string $content): array {
		if (strlen($content) > self::MAX_WRITE_BYTES) {
			throw new RuntimeException('File content exceeds write limit (' . self::MAX_WRITE_BYTES . ' bytes).');
		}

		$target = $this->resolveWritableTarget($relativePath);
		$directory = dirname($target);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new RuntimeException('Unable to create directory: ' . $this->relativePath($directory));
		}

		$temp = $target . '.evolution-' . bin2hex(random_bytes(5)) . '.tmp';
		if (file_put_contents($temp, $content, LOCK_EX) === false) {
			throw new RuntimeException('Unable to write temporary file: ' . $this->relativePath($temp));
		}
		if (!rename($temp, $target)) {
			@unlink($temp);
			throw new RuntimeException('Unable to move temporary file to target: ' . $relativePath);
		}

		return [
			'ok' => true,
			'path' => $this->relativePath($target),
			'bytes' => strlen($content),
			'sha256' => hash('sha256', $content)
		];
	}

	/** @return array<string,mixed> */
	public function deletePath(string $relativePath, bool $recursive = false): array {
		$target = $this->resolveWritableExistingPath($relativePath);
		$relative = $this->relativePath($target);
		if ($relative === 'plugin' || $relative === '') {
			throw new RuntimeException('Refusing to delete the workspace or complete plugin directory.');
		}

		if (is_link($target) || is_file($target)) {
			if (!unlink($target)) {
				throw new RuntimeException('Unable to delete file: ' . $relative);
			}
			return ['ok' => true, 'path' => $relative, 'type' => 'file'];
		}

		if (!is_dir($target)) {
			throw new RuntimeException('Path does not exist: ' . $relativePath);
		}
		if (is_dir($target . DIRECTORY_SEPARATOR . '.git') || is_file($target . DIRECTORY_SEPARATOR . '.git')) {
			throw new RuntimeException('Refusing to delete a Git repository root. Remove repository contents explicitly and keep version control intact: ' . $relative);
		}

		$children = iterator_count(new FilesystemIterator($target, FilesystemIterator::SKIP_DOTS));
		if ($children > 0 && !$recursive) {
			throw new RuntimeException('Directory is not empty. Set recursive=true to remove it: ' . $relative);
		}

		if ($recursive) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $item) {
				$path = $item->getPathname();
				if ($item->isLink() || $item->isFile()) {
					if (!unlink($path)) {
						throw new RuntimeException('Unable to delete file: ' . $this->relativePath($path));
					}
				} elseif (!rmdir($path)) {
					throw new RuntimeException('Unable to delete directory: ' . $this->relativePath($path));
				}
			}
		}

		if (!rmdir($target)) {
			throw new RuntimeException('Unable to delete directory: ' . $relative);
		}

		return ['ok' => true, 'path' => $relative, 'type' => 'directory'];
	}

	/** @return array<string,mixed> */
	public function getGitStatus(): array {
		$workspace = $this->requireWorkspace();
		$root = $this->getGitRoot();
		if ($root === '' || rtrim($root, DIRECTORY_SEPARATOR) !== rtrim($workspace, DIRECTORY_SEPARATOR)) {
			return [
				'available' => false,
				'clean' => false,
				'root' => $root,
				'repositories' => [],
				'unmanaged_plugins' => [],
				'output' => '',
				'error' => 'The configured workspace is not the root of a Git repository.'
			];
		}

		$repositories = [];
		$clean = true;
		$output = [];
		foreach ($this->getGitRepositories() as $relative => $repository) {
			$status = $this->runProcess(['git', 'status', '--porcelain=v1', '--branch'], $repository);
			$repositoryClean = $status['exit_code'] === 0 && $this->isStatusOutputClean($status['stdout']);
			$clean = $clean && $repositoryClean;
			$repositories[$relative] = [
				'path' => $repository,
				'head' => $this->getGitHeadForRepository($repository),
				'clean' => $repositoryClean,
				'status' => trim($status['stdout']),
				'error' => trim($status['stderr'])
			];
			if (trim($status['stdout']) !== '') {
				$output[] = '[' . $relative . "]\n" . trim($status['stdout']);
			}
		}

		$unmanaged = $this->getUnmanagedPluginDirectories();
		if ($unmanaged !== []) {
			$clean = false;
		}

		return [
			'available' => true,
			'clean' => $clean,
			'root' => $root,
			'repositories' => $repositories,
			'unmanaged_plugins' => $unmanaged,
			'output' => implode("\n\n", $output),
			'error' => ''
		];
	}

	public function getGitHead(): string {
		return $this->getGitHeadForRepository($this->requireWorkspace());
	}

	public function getGitRoot(): string {
		$result = $this->runProcess(['git', 'rev-parse', '--show-toplevel'], $this->requireWorkspace());
		if ($result['exit_code'] !== 0) {
			return '';
		}
		$root = realpath(trim($result['stdout']));
		return is_string($root) ? $root : '';
	}

	public function isGitClean(): bool {
		$status = $this->getGitStatus();
		return ($status['available'] ?? false) === true && ($status['clean'] ?? false) === true;
	}

	/** @return array<string,mixed> */
	public function createGitSnapshot(): array {
		$status = $this->getGitStatus();
		if (($status['available'] ?? false) !== true) {
			throw new RuntimeException('Unable to create Git snapshot: ' . (string)($status['error'] ?? 'Git unavailable.'));
		}
		if (($status['unmanaged_plugins'] ?? []) !== []) {
			throw new RuntimeException('Unable to create safe Git snapshot because plugin directories are not version controlled: ' . implode(', ', $status['unmanaged_plugins']));
		}

		$repositories = [];
		foreach ($status['repositories'] ?? [] as $relative => $repository) {
			$head = trim((string)($repository['head'] ?? ''));
			if ($head === '') {
				throw new RuntimeException('Git repository has no HEAD revision: ' . $relative);
			}
			$repositories[(string)$relative] = $head;
		}

		return [
			'repositories' => $repositories,
			'plugin_directories' => $this->getPluginDirectories()
		];
	}

	/** @param array<string,mixed> $snapshot */
	public function assertGitSnapshot(array $snapshot): void {
		$expectedRepositories = is_array($snapshot['repositories'] ?? null) ? $snapshot['repositories'] : [];
		if ($expectedRepositories === []) {
			throw new RuntimeException('Approved change has no Git repository snapshot. Re-run analysis.');
		}

		$current = $this->getGitStatus();
		if (($current['available'] ?? false) !== true) {
			throw new RuntimeException('Git repository structure is no longer available. Re-run analysis.');
		}
		if (($current['unmanaged_plugins'] ?? []) !== []) {
			throw new RuntimeException('Unversioned plugin directories are present: ' . implode(', ', $current['unmanaged_plugins']));
		}

		$currentRepositories = is_array($current['repositories'] ?? null) ? $current['repositories'] : [];
		if (array_keys($currentRepositories) !== array_keys($expectedRepositories)) {
			throw new RuntimeException('Git repository composition changed since analysis. Re-run analysis.');
		}
		foreach ($expectedRepositories as $relative => $head) {
			$currentHead = trim((string)($currentRepositories[$relative]['head'] ?? ''));
			if ($currentHead !== trim((string)$head)) {
				throw new RuntimeException('Git HEAD changed since analysis for ' . $relative . '. Re-run analysis.');
			}
			if (($currentRepositories[$relative]['clean'] ?? false) !== true) {
				throw new RuntimeException('Git working tree is not clean: ' . $relative . '. Commit or discard existing changes before Apply.');
			}
		}
	}

	public function getGitDiff(): string {
		$sections = [];
		foreach ($this->getGitRepositories() as $relative => $repository) {
			$diff = $this->runProcess(['git', 'diff', '--no-ext-diff', '--no-color'], $repository);
			if ($diff['exit_code'] !== 0) {
				throw new RuntimeException('Git diff failed for ' . $relative . ': ' . trim($diff['stderr']));
			}
			$untracked = $this->getUntrackedPathsForRepository($repository, $relative);
			$content = trim($diff['stdout']);
			if ($untracked !== []) {
				$content .= ($content !== '' ? "\n\n" : '') . "Untracked files:\n" . implode("\n", array_map(static fn(string $path): string => '+ ' . $path, $untracked));
			}
			if ($content !== '') {
				$sections[] = 'Repository ' . $relative . ":\n" . $content;
			}
		}

		$unmanaged = $this->getUnmanagedPluginDirectories();
		foreach ($unmanaged as $plugin) {
			$files = $this->listFiles($plugin, 12, 2000);
			$sections[] = 'New/unversioned plugin tree ' . $plugin . ":\n" . implode("\n", array_map(static fn(string $path): string => '+ ' . $path, $files));
		}

		return $this->limitText(implode("\n\n", $sections), self::MAX_PROCESS_OUTPUT_BYTES);
	}

	/** @return array<int,string> */
	public function getChangedPaths(): array {
		$result = [];
		foreach ($this->getGitRepositories() as $relative => $repository) {
			$tracked = $this->runProcess(['git', 'diff', '--name-only', 'HEAD'], $repository);
			$paths = $tracked['exit_code'] === 0 ? preg_split('/\R/', trim($tracked['stdout'])) : [];
			$paths = is_array($paths) ? $paths : [];
			$paths = array_merge($paths, $this->getUntrackedPathsForRepository($repository, $relative, false));
			foreach ($paths as $path) {
				$path = trim((string)$path);
				if ($path === '') continue;
				$workspacePath = $relative === '.' ? $path : $relative . '/' . $path;
				$result[$workspacePath] = $workspacePath;
			}
		}

		foreach ($this->getUnmanagedPluginDirectories() as $plugin) {
			foreach ($this->listFiles($plugin, 12, 2000) as $path) {
				if (!str_ends_with($path, '/')) {
					$result[$path] = $path;
				}
			}
		}

		ksort($result);
		return array_values($result);
	}

	/** @return array<string,mixed> */
	public function validateChangedPhp(): array {
		$workspace = $this->requireWorkspace();
		$files = array_values(array_filter(
			$this->getChangedPaths(),
			static fn(string $path): bool => str_ends_with(strtolower($path), '.php')
				&& is_file($workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path))
		));
		$results = [];
		$ok = true;

		foreach ($files as $file) {
			$absolute = $this->resolveExistingPath($file, false);
			$result = $this->runProcess([PHP_BINARY, '-l', $absolute], $this->requireWorkspace());
			$passed = $result['exit_code'] === 0;
			$ok = $ok && $passed;
			$results[] = [
				'file' => $file,
				'ok' => $passed,
				'output' => trim($result['stdout'] . "\n" . $result['stderr'])
			];
		}

		return [
			'ok' => $ok,
			'files' => $results,
			'count' => count($results)
		];
	}

	/** @return array<string,mixed> */
	public function refreshClassMap(): array {
		try {
			$this->classMap->generate(true);
			return ['ok' => true, 'message' => 'Class map regenerated successfully.'];
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => $e->getMessage(), 'type' => get_class($e)];
		}
	}

	/** @return array<string,mixed> */
	public function runTests(): array {
		$workspace = $this->requireWorkspace();
		$vendorPhpunit = $workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';

		if (is_file($vendorPhpunit)) {
			$command = [PHP_BINARY, $vendorPhpunit, '--colors=never'];
		} elseif (is_executable('/usr/local/bin/phpunit')) {
			$command = ['/usr/local/bin/phpunit', '--colors=never'];
		} elseif (is_executable('/usr/bin/phpunit')) {
			$command = ['/usr/bin/phpunit', '--colors=never'];
		} else {
			return [
				'ok' => true,
				'skipped' => true,
				'message' => 'PHPUnit executable not found. Test execution skipped.'
			];
		}

		$result = $this->runProcess($command, $workspace, 180);
		return [
			'ok' => $result['exit_code'] === 0,
			'skipped' => false,
			'exit_code' => $result['exit_code'],
			'output' => $this->limitText(trim($result['stdout'] . "\n" . $result['stderr']), self::MAX_PROCESS_OUTPUT_BYTES)
		];
	}

	/** @param array<string,mixed> $snapshot @return array<string,mixed> */
	public function rollbackToSnapshot(array $snapshot): array {
		$repositories = is_array($snapshot['repositories'] ?? null) ? $snapshot['repositories'] : [];
		if ($repositories === []) {
			return ['ok' => false, 'message' => 'Rollback refused because the accepted Git snapshot is missing.'];
		}

		$output = [];
		foreach ($repositories as $relative => $expectedHead) {
			$repository = $relative === '.'
				? $this->requireWorkspace()
				: $this->requireWorkspace() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative);
			if (!is_dir($repository)) {
				return ['ok' => false, 'message' => 'Rollback refused because repository directory is missing: ' . $relative];
			}
			$currentHead = $this->getGitHeadForRepository($repository);
			if ($currentHead !== trim((string)$expectedHead)) {
				return ['ok' => false, 'message' => 'Rollback refused because Git HEAD changed for ' . $relative . '.'];
			}
			$reset = $this->runProcess(['git', 'reset', '--hard', (string)$expectedHead], $repository);
			if ($reset['exit_code'] !== 0) {
				return ['ok' => false, 'message' => 'Git reset failed for ' . $relative . ': ' . trim($reset['stderr'])];
			}
			$clean = $this->runProcess(['git', 'clean', '-fd'], $repository);
			if ($clean['exit_code'] !== 0) {
				return ['ok' => false, 'message' => 'Git clean failed for ' . $relative . ': ' . trim($clean['stderr'])];
			}
			$output[] = '[' . $relative . '] ' . trim($reset['stdout'] . "\n" . $clean['stdout']);
		}

		$beforePlugins = is_array($snapshot['plugin_directories'] ?? null) ? $snapshot['plugin_directories'] : [];
		$beforePlugins = array_fill_keys(array_map('strval', $beforePlugins), true);
		foreach ($this->getPluginDirectories() as $plugin) {
			if (isset($beforePlugins[$plugin])) continue;
			$absolute = $this->requireWorkspace() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $plugin);
			if (is_dir($absolute . DIRECTORY_SEPARATOR . '.git')) {
				return ['ok' => false, 'message' => 'Rollback refused to remove newly created Git repository automatically: ' . $plugin];
			}
			$this->removeDirectoryTree($absolute);
			$output[] = '[new plugin removed] ' . $plugin;
		}

		return [
			'ok' => true,
			'message' => 'Workspace repositories restored to the accepted revisions.',
			'output' => trim(implode("\n", $output))
		];
	}

	public function hasPhpUnit(): bool {
		$workspace = $this->requireWorkspace();
		return is_file($workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit')
			|| is_executable('/usr/local/bin/phpunit')
			|| is_executable('/usr/bin/phpunit');
	}

	private function requireWorkspace(): string {
		$configured = $this->configuration->getWorkspace();
		if ($configured === '') {
			throw new RuntimeException('Missing configuration value: [evolution] workspace');
		}
		$workspace = realpath($configured);
		if (!is_string($workspace) || !is_dir($workspace)) {
			throw new RuntimeException('Evolution workspace does not exist or is not a directory: ' . $configured);
		}
		return rtrim($workspace, DIRECTORY_SEPARATOR);
	}

	private function normalizeRelativePath(string $relativePath): string {
		if (str_contains($relativePath, "\0")) {
			throw new RuntimeException('Path contains a null byte.');
		}
		$relativePath = str_replace('\\', '/', trim($relativePath));
		$relativePath = ltrim($relativePath, '/');
		if ($relativePath === '.') {
			return '';
		}
		$parts = [];
		foreach (explode('/', $relativePath) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}
			if ($part === '..') {
				throw new RuntimeException('Parent path traversal is not allowed: ' . $relativePath);
			}
			$parts[] = $part;
		}
		if (in_array('.git', $parts, true)) {
			throw new RuntimeException('Direct access to .git is not allowed.');
		}
		return implode('/', $parts);
	}

	private function resolveExistingPath(string $relativePath, bool $allowDirectory): string {
		$workspace = $this->requireWorkspace();
		$relative = $this->normalizeRelativePath($relativePath);
		$candidate = $relative === '' ? $workspace : $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		$path = realpath($candidate);
		if (!is_string($path) || !$this->isInside($path, $workspace)) {
			throw new RuntimeException('Path does not exist inside the Evolution workspace: ' . $relativePath);
		}
		if (!$allowDirectory && is_dir($path)) {
			throw new RuntimeException('Expected a file but received a directory: ' . $relativePath);
		}
		return $path;
	}

	private function resolveWritableExistingPath(string $relativePath): string {
		$workspace = $this->requireWorkspace();
		$relative = $this->normalizeRelativePath($relativePath);
		$candidate = $relative === ''
			? $workspace
			: $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		if (is_link($candidate)) {
			throw new RuntimeException('Evolution does not modify or delete symbolic links: ' . $relativePath);
		}

		$path = $this->resolveExistingPath($relativePath, true);
		$this->assertWriteAllowed($this->relativePath($path));
		return $path;
	}

	private function resolveWritableTarget(string $relativePath): string {
		$workspace = $this->requireWorkspace();
		$relative = $this->normalizeRelativePath($relativePath);
		if ($relative === '') {
			throw new RuntimeException('Target path must not be empty.');
		}
		$this->assertWriteAllowed($relative);

		$candidate = $workspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		$ancestor = dirname($candidate);
		while (!is_dir($ancestor)) {
			$parent = dirname($ancestor);
			if ($parent === $ancestor) {
				throw new RuntimeException('Unable to resolve target directory for: ' . $relativePath);
			}
			$ancestor = $parent;
		}
		$ancestorReal = realpath($ancestor);
		if (!is_string($ancestorReal) || !$this->isInside($ancestorReal, $workspace)) {
			throw new RuntimeException('Target path escapes the Evolution workspace: ' . $relativePath);
		}
		$this->assertWriteAllowed($this->relativePath($ancestorReal));

		if (is_link($candidate)) {
			throw new RuntimeException('Evolution does not write through symbolic links: ' . $relativePath);
		}
		if (file_exists($candidate)) {
			$existing = realpath($candidate);
			if (!is_string($existing) || !$this->isInside($existing, $workspace)) {
				throw new RuntimeException('Target path resolves outside the Evolution workspace: ' . $relativePath);
			}
			$this->assertWriteAllowed($this->relativePath($existing));
			if (is_dir($existing)) {
				throw new RuntimeException('Target path is a directory: ' . $relativePath);
			}
			return $existing;
		}

		return $candidate;
	}

	private function assertWriteAllowed(string $relativePath): void {
		$relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
		$segments = explode('/', trim($relativePath, '/'));
		if (in_array('.git', $segments, true)) {
			throw new RuntimeException('Evolution never writes directly to .git.');
		}
		if (!$this->configuration->isFrameworkWriteEnabled()) {
			if ($relativePath !== 'plugin' && !str_starts_with($relativePath, 'plugin/')) {
				throw new RuntimeException('Framework write is disabled. Writable paths must be inside plugin/.');
			}
		}
	}

	private function relativePath(string $path): string {
		$workspace = $this->requireWorkspace();
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		if ($path === $workspace) {
			return '';
		}
		if (!$this->isInside($path, $workspace)) {
			throw new RuntimeException('Path is outside the Evolution workspace.');
		}
		return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($workspace) + 1));
	}

	private function isInside(string $path, string $root): bool {
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		$root = rtrim($root, DIRECTORY_SEPARATOR);
		return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
	}

	private function isIgnoredPath(string $relativePath): bool {
		$relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
		foreach (['.git/', 'vendor/', 'tmp/', 'userfiles/', 'local/FileLogger/'] as $prefix) {
			if (str_starts_with($relativePath, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/** @return array{exit_code:int,stdout:string,stderr:string} */
	private function runProcess(array $command, string $cwd, int $timeoutSeconds = 30): array {
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];
		$process = @proc_open($command, $descriptors, $pipes, $cwd);
		if (!is_resource($process)) {
			return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Unable to start process: ' . implode(' ', $command)];
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$stdout = '';
		$stderr = '';
		$started = microtime(true);
		$exitCode = null;

		while (true) {
			$status = proc_get_status($process);
			$stdout .= (string)stream_get_contents($pipes[1]);
			$stderr .= (string)stream_get_contents($pipes[2]);
			if (strlen($stdout) > self::MAX_PROCESS_OUTPUT_BYTES) {
				$stdout = substr($stdout, 0, self::MAX_PROCESS_OUTPUT_BYTES);
			}
			if (strlen($stderr) > self::MAX_PROCESS_OUTPUT_BYTES) {
				$stderr = substr($stderr, 0, self::MAX_PROCESS_OUTPUT_BYTES);
			}
			if (!$status['running']) {
				$statusExitCode = (int)($status['exitcode'] ?? -1);
				if ($statusExitCode >= 0) {
					$exitCode = $statusExitCode;
				}
				break;
			}
			if ((microtime(true) - $started) > $timeoutSeconds) {
				proc_terminate($process);
				$stderr .= "\nProcess timed out after " . $timeoutSeconds . ' seconds.';
				$exitCode = 124;
				break;
			}
			usleep(20000);
		}

		$stdout .= (string)stream_get_contents($pipes[1]);
		$stderr .= (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$closeExitCode = proc_close($process);
		if ($exitCode === null) {
			$exitCode = $closeExitCode;
		}

		return [
			'exit_code' => $exitCode,
			'stdout' => $this->limitText($stdout, self::MAX_PROCESS_OUTPUT_BYTES),
			'stderr' => $this->limitText($stderr, self::MAX_PROCESS_OUTPUT_BYTES)
		];
	}

	private function isStatusOutputClean(string $output): bool {
		$lines = preg_split('/\R/', trim($output)) ?: [];
		foreach ($lines as $line) {
			if ($line === '' || str_starts_with($line, '## ')) {
				continue;
			}
			return false;
		}
		return true;
	}

	/** @return array<string,string> */
	private function getGitRepositories(): array {
		$workspace = $this->requireWorkspace();
		$repositories = ['.' => $workspace];
		$pluginRoot = $workspace . DIRECTORY_SEPARATOR . 'plugin';
		if (!is_dir($pluginRoot)) {
			return $repositories;
		}

		$entries = scandir($pluginRoot) ?: [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) continue;
			$directory = $pluginRoot . DIRECTORY_SEPARATOR . $entry;
			if (!is_dir($directory)) continue;
			$result = $this->runProcess(['git', 'rev-parse', '--show-toplevel'], $directory);
			if ($result['exit_code'] !== 0) continue;
			$root = realpath(trim($result['stdout']));
			$directoryReal = realpath($directory);
			if (is_string($root) && is_string($directoryReal) && $root === $directoryReal) {
				$repositories['plugin/' . $entry] = $directoryReal;
			}
		}
		ksort($repositories);
		return $repositories;
	}

	/** @return array<int,string> */
	private function getPluginDirectories(): array {
		$workspace = $this->requireWorkspace();
		$pluginRoot = $workspace . DIRECTORY_SEPARATOR . 'plugin';
		if (!is_dir($pluginRoot)) return [];
		$result = [];
		foreach (scandir($pluginRoot) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) continue;
			if (is_dir($pluginRoot . DIRECTORY_SEPARATOR . $entry)) {
				$result[] = 'plugin/' . $entry;
			}
		}
		sort($result);
		return $result;
	}

	/** @return array<int,string> */
	private function getUnmanagedPluginDirectories(): array {
		$workspace = $this->requireWorkspace();
		$repositories = $this->getGitRepositories();
		$result = [];
		foreach ($this->getPluginDirectories() as $plugin) {
			if (isset($repositories[$plugin])) continue;
			$tracked = $this->runProcess(['git', 'ls-files', '--', $plugin], $workspace);
			if ($tracked['exit_code'] === 0 && trim($tracked['stdout']) !== '') continue;
			$result[] = $plugin;
		}
		return $result;
	}

	private function getGitHeadForRepository(string $repository): string {
		$result = $this->runProcess(['git', 'rev-parse', 'HEAD'], $repository);
		return $result['exit_code'] === 0 ? trim($result['stdout']) : '';
	}

	/** @return array<int,string> */
	private function getUntrackedPathsForRepository(string $repository, string $prefix, bool $workspacePaths = true): array {
		$result = $this->runProcess(['git', 'ls-files', '--others', '--exclude-standard'], $repository);
		if ($result['exit_code'] !== 0) return [];
		$paths = preg_split('/\R/', trim($result['stdout'])) ?: [];
		$out = [];
		foreach ($paths as $path) {
			$path = trim((string)$path);
			if ($path === '') continue;
			$out[] = $workspacePaths && $prefix !== '.' ? $prefix . '/' . $path : $path;
		}
		return $out;
	}

	private function removeDirectoryTree(string $directory): void {
		$workspace = $this->requireWorkspace();
		$realParent = realpath(dirname($directory));
		if (!is_string($realParent) || !$this->isInside($realParent, $workspace)) {
			throw new RuntimeException('Refusing to remove directory outside Evolution workspace: ' . $directory);
		}
		if (is_link($directory)) {
			throw new RuntimeException('Refusing to remove symbolic link directory during rollback: ' . $directory);
		}
		if (!is_dir($directory)) return;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			$path = $item->getPathname();
			if ($item->isLink() || $item->isFile()) {
				if (!unlink($path)) throw new RuntimeException('Rollback could not remove file: ' . $path);
			} elseif (!rmdir($path)) {
				throw new RuntimeException('Rollback could not remove directory: ' . $path);
			}
		}
		if (!rmdir($directory)) throw new RuntimeException('Rollback could not remove directory: ' . $directory);
	}

	private function limitText(string $text, int $maxLength): string {
		if (strlen($text) <= $maxLength) {
			return $text;
		}
		return substr($text, 0, $maxLength) . "\n...[truncated]";
	}
}
