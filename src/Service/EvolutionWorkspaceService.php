<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Api\IClassMap;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

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
		return [
			'application_root' => $this->requireWorkspace(),
			'writable_plugin' => $this->configuration->getWorkspacePlugin(),
			'writable_root' => $this->configuration->getWorkspacePluginPath(),
			'git_required' => $this->configuration->isGitRequired(),
			'git' => $this->getGitStatus(),
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
		$relative = $this->normalizeRelativePath($relativePath);
		if ($this->isIgnoredPath($relative)) {
			throw new RuntimeException('Path is not readable through the Evolution source tool: ' . $relativePath);
		}
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

		$relative = $this->normalizeRelativePath($relativePath);
		if ($relative !== '' && $this->isIgnoredPath($relative)) {
			throw new RuntimeException('Path is not searchable through the Evolution source tool: ' . $relativePath);
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
				if ($this->isIgnoredPath($relative) || $item->getSize() > self::MAX_READ_BYTES) {
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

		try {
			if (str_ends_with(strtolower($target), '.php')) {
				$lint = $this->runProcess([PHP_BINARY, '-l', $temp], $this->requireWorkspace());
				if ($lint['exit_code'] !== 0) {
					throw new RuntimeException('Generated PHP is not syntactically valid: ' . trim($lint['stdout'] . "\n" . $lint['stderr']));
				}
			}
			if (!rename($temp, $target)) {
				throw new RuntimeException('Unable to move temporary file to target: ' . $relativePath);
			}
		} catch (Throwable $e) {
			@unlink($temp);
			throw $e;
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
		if ($relative === $this->configuration->getWorkspacePluginPath()) {
			throw new RuntimeException('Refusing to delete the EvolutionWorkspace repository root. Delete individual contents instead.');
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
			throw new RuntimeException('Refusing to delete a Git repository root: ' . $relative);
		}

		$children = iterator_count(new FilesystemIterator($target, FilesystemIterator::SKIP_DOTS));
		if ($children > 0 && !$recursive) {
			throw new RuntimeException('Directory is not empty. Set recursive=true to remove it: ' . $relative);
		}
		if ($recursive) {
			$this->removeDirectoryTree($target);
		} elseif (!rmdir($target)) {
			throw new RuntimeException('Unable to delete directory: ' . $relative);
		}

		return ['ok' => true, 'path' => $relative, 'type' => 'directory'];
	}

	/** @return array<string,mixed> */
	public function getGitStatus(): array {
		$repository = $this->getWorkspacePluginDirectory();
		$root = $this->getGitRoot();
		if ($root === '' || rtrim($root, DIRECTORY_SEPARATOR) !== rtrim($repository, DIRECTORY_SEPARATOR)) {
			return [
				'available' => false,
				'clean' => false,
				'root' => $root,
				'branch' => '',
				'head' => '',
				'output' => '',
				'error' => 'plugin/EvolutionWorkspace must be its own Git repository.'
			];
		}

		$status = $this->runProcess(['git', 'status', '--porcelain=v1', '--branch'], $repository);
		if ($status['exit_code'] !== 0) {
			return [
				'available' => false,
				'clean' => false,
				'root' => $root,
				'branch' => '',
				'head' => '',
				'output' => trim($status['stdout']),
				'error' => trim($status['stderr'])
			];
		}

		$branchResult = $this->runProcess(['git', 'branch', '--show-current'], $repository);
		$head = $this->getGitHeadForRepository($repository);

		return [
			'available' => true,
			'clean' => $this->isStatusOutputClean($status['stdout']),
			'root' => $root,
			'branch' => $branchResult['exit_code'] === 0 ? trim($branchResult['stdout']) : '',
			'head' => $head,
			'output' => trim($status['stdout']),
			'error' => trim($status['stderr'])
		];
	}

	public function getGitHead(): string {
		return $this->getGitHeadForRepository($this->getWorkspacePluginDirectory());
	}

	public function getGitRoot(): string {
		$repository = $this->getWorkspacePluginDirectory();
		$result = $this->runProcess(['git', 'rev-parse', '--show-toplevel'], $repository);
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

	public function createSourceFingerprint(): string {
		$workspace = $this->requireWorkspace();
		$files = [];

		foreach (['src', 'plugin'] as $relativeRoot) {
			$root = $workspace . DIRECTORY_SEPARATOR . $relativeRoot;
			if (!is_dir($root)) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $item) {
				if (!$item->isFile() || $item->isLink()) {
					continue;
				}
				$relative = $this->relativePath($item->getPathname());
				if ($this->isIgnoredPath($relative)) {
					continue;
				}
				$hash = hash_file('sha256', $item->getPathname());
				if (!is_string($hash)) {
					throw new RuntimeException('Unable to fingerprint source file: ' . $relative);
				}
				$files[$relative] = $hash;
			}
		}

		$configFile = $workspace . DIRECTORY_SEPARATOR . 'cnf' . DIRECTORY_SEPARATOR . 'config.ini';
		if (is_file($configFile)) {
			$hash = hash_file('sha256', $configFile);
			if (!is_string($hash)) {
				throw new RuntimeException('Unable to fingerprint configuration file: cnf/config.ini');
			}
			$files['cnf/config.ini'] = $hash;
		}

		ksort($files);
		$payload = '';
		foreach ($files as $relative => $hash) {
			$payload .= $relative . "\0" . $hash . "\n";
		}

		return hash('sha256', $payload);
	}

	public function assertSourceFingerprint(string $expectedFingerprint): void {
		$expectedFingerprint = trim($expectedFingerprint);
		if ($expectedFingerprint === '') {
			throw new RuntimeException('Approved change has no source fingerprint. Re-run analysis.');
		}

		$currentFingerprint = $this->createSourceFingerprint();
		if (!hash_equals($expectedFingerprint, $currentFingerprint)) {
			throw new RuntimeException('Application source changed since analysis. Re-run analysis before Apply.');
		}
	}

	/** @return array<string,mixed> */
	public function createGitSnapshot(): array {
		$status = $this->getGitStatus();
		if (($status['available'] ?? false) !== true) {
			throw new RuntimeException('Unable to create EvolutionWorkspace Git snapshot: ' . (string)($status['error'] ?? 'Git unavailable.'));
		}
		if (($status['clean'] ?? false) !== true) {
			throw new RuntimeException('EvolutionWorkspace Git repository must be clean before Apply.');
		}
		$head = trim((string)($status['head'] ?? ''));
		if ($head === '') {
			throw new RuntimeException('EvolutionWorkspace Git repository has no HEAD revision. Create the initial commit before Apply.');
		}

		return [
			'repository' => $this->configuration->getWorkspacePluginPath(),
			'head' => $head,
			'branch' => (string)($status['branch'] ?? '')
		];
	}

	/** @param array<string,mixed> $snapshot */
	public function assertGitSnapshot(array $snapshot): void {
		$expectedHead = $this->getSnapshotHead($snapshot);
		$status = $this->getGitStatus();
		if (($status['available'] ?? false) !== true) {
			throw new RuntimeException('EvolutionWorkspace Git repository is no longer available. Re-run analysis.');
		}
		if (trim((string)($status['head'] ?? '')) !== $expectedHead) {
			throw new RuntimeException('EvolutionWorkspace Git HEAD changed since the accepted snapshot. Re-run analysis.');
		}
		if (($status['clean'] ?? false) !== true) {
			throw new RuntimeException('EvolutionWorkspace Git working tree is not clean. Commit or discard existing changes before Apply.');
		}
	}

	public function getGitDiff(?string $baseHead = null): string {
		$repository = $this->getWorkspacePluginDirectory();
		$args = ['git', 'diff', '--no-ext-diff', '--no-color'];
		if ($baseHead !== null && trim($baseHead) !== '') {
			$args[] = trim($baseHead);
		}
		$args[] = '--';
		$args[] = '.';
		$diff = $this->runProcess($args, $repository);
		if ($diff['exit_code'] !== 0) {
			throw new RuntimeException('EvolutionWorkspace Git diff failed: ' . trim($diff['stderr']));
		}

		$untracked = $this->getUntrackedPathsForRepository($repository);
		$content = trim($diff['stdout']);
		if ($untracked !== []) {
			$content .= ($content !== '' ? "\n\n" : '') . "Untracked files:\n" . implode("\n", array_map(
				fn(string $path): string => '+ ' . $this->configuration->getWorkspacePluginPath() . '/' . $path,
				$untracked
			));
		}

		return $this->limitText($content, self::MAX_PROCESS_OUTPUT_BYTES);
	}

	/** @return array<int,string> */
	public function getChangedPaths(?string $baseHead = null): array {
		$repository = $this->getWorkspacePluginDirectory();
		$args = ['git', 'diff', '--name-only'];
		if ($baseHead !== null && trim($baseHead) !== '') {
			$args[] = trim($baseHead);
		} else {
			$args[] = 'HEAD';
		}
		$args[] = '--';
		$args[] = '.';
		$tracked = $this->runProcess($args, $repository);
		if ($tracked['exit_code'] !== 0) {
			throw new RuntimeException('Unable to determine changed EvolutionWorkspace paths: ' . trim($tracked['stderr']));
		}

		$paths = preg_split('/\R/', trim($tracked['stdout'])) ?: [];
		$paths = array_merge($paths, $this->getUntrackedPathsForRepository($repository));
		$result = [];
		foreach ($paths as $path) {
			$path = trim((string)$path);
			if ($path === '') {
				continue;
			}
			$workspacePath = $this->configuration->getWorkspacePluginPath() . '/' . str_replace('\\', '/', $path);
			$result[$workspacePath] = $workspacePath;
		}

		ksort($result);
		return array_values($result);
	}

	/** @return array<string,mixed> */
	public function validateChangedPhp(?string $baseHead = null): array {
		$workspace = $this->requireWorkspace();
		$files = array_values(array_filter(
			$this->getChangedPaths($baseHead),
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
			return ['ok' => true, 'message' => 'BASE3 class map regenerated successfully.'];
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => $e->getMessage(), 'type' => $e::class];
		}
	}

	/** @return array<string,mixed> */
	public function runTests(): array {
		$workspace = $this->requireWorkspace();
		$testDirectory = $this->getWorkspacePluginDirectory() . DIRECTORY_SEPARATOR . 'test';
		if (!is_dir($testDirectory)) {
			return [
				'ok' => true,
				'skipped' => true,
				'message' => 'EvolutionWorkspace has no test directory. Test execution skipped.'
			];
		}

		$vendorPhpunit = $workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
		if (is_file($vendorPhpunit)) {
			$command = [PHP_BINARY, $vendorPhpunit, '--colors=never', $testDirectory];
		} elseif (is_executable('/usr/local/bin/phpunit')) {
			$command = ['/usr/local/bin/phpunit', '--colors=never', $testDirectory];
		} elseif (is_executable('/usr/bin/phpunit')) {
			$command = ['/usr/bin/phpunit', '--colors=never', $testDirectory];
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
		try {
			$head = $this->getSnapshotHead($snapshot);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => $e->getMessage()];
		}

		$repository = $this->getWorkspacePluginDirectory();
		$reset = $this->runProcess(['git', 'reset', '--hard', $head], $repository);
		if ($reset['exit_code'] !== 0) {
			return ['ok' => false, 'message' => 'EvolutionWorkspace Git reset failed: ' . trim($reset['stderr'])];
		}
		$clean = $this->runProcess(['git', 'clean', '-fd'], $repository);
		if ($clean['exit_code'] !== 0) {
			return ['ok' => false, 'message' => 'EvolutionWorkspace Git clean failed: ' . trim($clean['stderr'])];
		}

		return [
			'ok' => true,
			'message' => 'EvolutionWorkspace restored to the accepted revision.',
			'output' => trim($reset['stdout'] . "\n" . $clean['stdout'])
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
			throw new RuntimeException('Evolution application root does not exist or is not a directory: ' . $configured);
		}
		return rtrim($workspace, DIRECTORY_SEPARATOR);
	}

	private function getWorkspacePluginDirectory(): string {
		$path = $this->requireWorkspace() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->configuration->getWorkspacePluginPath());
		$real = realpath($path);
		if (!is_string($real) || !is_dir($real)) {
			throw new RuntimeException('EvolutionWorkspace plugin directory does not exist: ' . $path);
		}
		return rtrim($real, DIRECTORY_SEPARATOR);
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
			throw new RuntimeException('Path does not exist inside the Evolution application root: ' . $relativePath);
		}
		if (!$allowDirectory && is_dir($path)) {
			throw new RuntimeException('Expected a file but received a directory: ' . $relativePath);
		}
		return $path;
	}

	private function resolveWritableExistingPath(string $relativePath): string {
		$relative = $this->normalizeRelativePath($relativePath);
		$this->assertWriteAllowed($relative);
		$candidate = $this->requireWorkspace() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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
			throw new RuntimeException('Target path escapes the Evolution application root: ' . $relativePath);
		}
		$this->assertWriteAllowed($this->relativePath($ancestorReal));

		if (is_link($candidate)) {
			throw new RuntimeException('Evolution does not write through symbolic links: ' . $relativePath);
		}
		if (file_exists($candidate)) {
			$existing = realpath($candidate);
			if (!is_string($existing) || !$this->isInside($existing, $workspace)) {
				throw new RuntimeException('Target path resolves outside the Evolution application root: ' . $relativePath);
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
		$relativePath = str_replace('\\', '/', trim($relativePath, '/'));
		$segments = explode('/', $relativePath);
		if (in_array('.git', $segments, true)) {
			throw new RuntimeException('Evolution never writes directly to .git.');
		}

		$root = $this->configuration->getWorkspacePluginPath();
		if ($relativePath !== $root && !str_starts_with($relativePath, $root . '/')) {
			throw new RuntimeException('Evolution source mutation is restricted to ' . $root . '/.');
		}
	}

	private function relativePath(string $path): string {
		$workspace = $this->requireWorkspace();
		$path = rtrim($path, DIRECTORY_SEPARATOR);
		if ($path === $workspace) {
			return '';
		}
		if (!$this->isInside($path, $workspace)) {
			throw new RuntimeException('Path is outside the Evolution application root.');
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
		$segments = explode('/', trim($relativePath, '/'));
		if (in_array('.git', $segments, true)) {
			return true;
		}
		foreach (['vendor', 'tmp', 'userfiles', 'local/FileLogger', 'local/secret'] as $prefix) {
			if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
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
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '##')) {
				continue;
			}
			return false;
		}
		return true;
	}

	private function getGitHeadForRepository(string $repository): string {
		$result = $this->runProcess(['git', 'rev-parse', 'HEAD'], $repository);
		return $result['exit_code'] === 0 ? trim($result['stdout']) : '';
	}

	/** @return array<int,string> */
	private function getUntrackedPathsForRepository(string $repository): array {
		$result = $this->runProcess(['git', 'ls-files', '--others', '--exclude-standard'], $repository);
		if ($result['exit_code'] !== 0) {
			return [];
		}
		$paths = preg_split('/\R/', trim($result['stdout'])) ?: [];
		$resultPaths = [];
		foreach ($paths as $path) {
			$path = trim($path);
			if ($path !== '') {
				$resultPaths[$path] = $path;
			}
		}
		ksort($resultPaths);
		return array_values($resultPaths);
	}

	/** @param array<string,mixed> $snapshot */
	private function getSnapshotHead(array $snapshot): string {
		$head = trim((string)($snapshot['head'] ?? ''));
		if ($head === '') {
			throw new RuntimeException('Accepted EvolutionWorkspace Git snapshot has no HEAD revision.');
		}
		return $head;
	}

	private function removeDirectoryTree(string $directory): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
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
		if (!rmdir($directory)) {
			throw new RuntimeException('Unable to delete directory: ' . $this->relativePath($directory));
		}
	}

	private function limitText(string $text, int $maxLength): string {
		if (strlen($text) <= $maxLength) {
			return $text;
		}
		return substr($text, 0, $maxLength) . "\n...[truncated]";
	}
}
