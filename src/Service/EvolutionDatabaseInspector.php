<?php declare(strict_types=1);

namespace Evolution\Service;

use Base3\Api\IContainer;
use Base3\Database\Api\IDatabase;
use RuntimeException;

final class EvolutionDatabaseInspector {

	public function __construct(
		private readonly IContainer $container
	) {}

	/** @return array<string,mixed> */
	public function inspect(?string $table = null): array {
		$database = $this->requireDatabase();
		$database->connect();
		if (!$database->connected()) {
			throw new RuntimeException('Database connection is not available. Check [database] host, user, pass and name.');
		}

		$tables = $database->listQuery('SHOW TABLES');
		$tables = array_values(array_map(static fn(mixed $value): string => (string)$value, $tables));
		sort($tables);

		$table = trim((string)$table);
		if ($table === '') {
			return [
			'tables' => $tables,
			'count' => count($tables)
			];
		}

		if (!in_array($table, $tables, true)) {
			throw new RuntimeException('Unknown database table: ' . $table);
		}

		$escapedTable = str_replace('`', '``', $table);

		return [
			'table' => $table,
			'columns' => $database->multiQuery('SHOW FULL COLUMNS FROM `' . $escapedTable . '`'),
			'indexes' => $database->multiQuery('SHOW INDEX FROM `' . $escapedTable . '`')
		];
	}

	private function requireDatabase(): IDatabase {
		if (!$this->container->has(IDatabase::class)) {
			throw new RuntimeException('IDatabase is not registered. Wire IDatabase in the project plugin.');
		}

		$database = $this->container->get(IDatabase::class);
		if (!$database instanceof IDatabase) {
			throw new RuntimeException('Configured IDatabase service does not implement IDatabase.');
		}

		return $database;
	}
}
