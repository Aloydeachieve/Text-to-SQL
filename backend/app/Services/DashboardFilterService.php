<?php

namespace App\Services;

use App\Models\SavedQuery;
use Carbon\Carbon;
use InvalidArgumentException;

class DashboardFilterService
{
    protected QueryInterpretationService $interpretationService;
    protected DatabaseSchemaService $defaultSchemaService;

    public function __construct(
        QueryInterpretationService $interpretationService,
        DatabaseSchemaService $defaultSchemaService
    ) {
        $this->interpretationService = $interpretationService;
        $this->defaultSchemaService = $defaultSchemaService;
    }

    /**
     * Resolve date preset or custom date bounds into strict YYYY-MM-DD bounds.
     *
     * @param string|null $preset
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return array{from: string, to: string, preset: string, label: string}|null
     * @throws InvalidArgumentException
     */
    public function resolveDateRange(?string $preset, ?string $dateFrom = null, ?string $dateTo = null): ?array
    {
        $preset = $preset ? strtolower(trim($preset)) : null;

        if (empty($preset) || in_array($preset, ['all', 'all_time', 'none'], true)) {
            if (empty($dateFrom) && empty($dateTo)) {
                return null;
            }
            $preset = 'custom';
        }

        $now = Carbon::now();

        if ($preset === 'custom') {
            if (empty($dateFrom) || empty($dateTo)) {
                throw new InvalidArgumentException("Custom date range requires both 'from' and 'to' dates in YYYY-MM-DD format.");
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                throw new InvalidArgumentException("Dates must be in valid YYYY-MM-DD format.");
            }

            if ($dateFrom > $dateTo) {
                throw new InvalidArgumentException("Date 'from' ({$dateFrom}) must be before or equal to 'to' ({$dateTo}).");
            }

            return [
                'from' => $dateFrom,
                'to' => $dateTo,
                'preset' => 'custom',
                'label' => "{$dateFrom} – {$dateTo}",
            ];
        }

        return match ($preset) {
            'today' => [
                'from' => $now->toDateString(),
                'to' => $now->toDateString(),
                'preset' => 'today',
                'label' => 'Today',
            ],
            'yesterday' => [
                'from' => $now->copy()->subDay()->toDateString(),
                'to' => $now->copy()->subDay()->toDateString(),
                'preset' => 'yesterday',
                'label' => 'Yesterday',
            ],
            'last_7_days' => [
                'from' => $now->copy()->subDays(6)->toDateString(),
                'to' => $now->toDateString(),
                'preset' => 'last_7_days',
                'label' => 'Last 7 Days',
            ],
            'last_30_days' => [
                'from' => $now->copy()->subDays(29)->toDateString(),
                'to' => $now->toDateString(),
                'preset' => 'last_30_days',
                'label' => 'Last 30 Days',
            ],
            'this_month' => [
                'from' => $now->copy()->startOfMonth()->toDateString(),
                'to' => $now->toDateString(),
                'preset' => 'this_month',
                'label' => 'This Month',
            ],
            'last_month' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'preset' => 'last_month',
                'label' => 'Last Month',
            ],
            'this_quarter' => [
                'from' => $now->copy()->firstOfQuarter()->toDateString(),
                'to' => $now->toDateString(),
                'preset' => 'this_quarter',
                'label' => 'This Quarter',
            ],
            default => throw new InvalidArgumentException("Unsupported date preset: {$preset}"),
        };
    }

    /**
     * Analyze a saved query to determine if it can safely accept a global date filter.
     *
     * @param SavedQuery $savedQuery
     * @param array<string, list<string>>|null $schema
     * @param string $driver
     * @return array{
     *     supported: bool,
     *     source_table: ?string,
     *     date_column: ?string,
     *     table_or_alias: ?string,
     *     driver: string,
     *     reason: ?string
     * }
     */
    public function analyzeFilterCompatibility(SavedQuery $savedQuery, ?array $schema = null, string $driver = 'mysql'): array
    {
        $sql = trim($savedQuery->sql);
        $driver = strtolower($savedQuery->dialect ?: $driver);

        // Disallow filtering on complex compound queries (e.g. UNION)
        if (preg_match('/\bUNION\b/i', $sql)) {
            return [
                'supported' => false,
                'source_table' => null,
                'date_column' => null,
                'table_or_alias' => null,
                'driver' => $driver,
                'reason' => 'Compound queries (UNION) do not support automatic date filtering.',
            ];
        }

        $aliases = $this->interpretationService->extractTableAliases($sql);
        $tables = $this->interpretationService->extractTables($sql, $aliases);

        if (empty($tables)) {
            return [
                'supported' => false,
                'source_table' => null,
                'date_column' => null,
                'table_or_alias' => null,
                'driver' => $driver,
                'reason' => 'No query source tables identified.',
            ];
        }

        $schemaTables = $schema ?: $this->defaultSchemaService->getTablesAndColumns();

        // Check tables in priority order: transactional tables first ('orders', 'transactions', etc.)
        $candidateTable = null;
        $candidateCol = null;

        $preferredDateCols = ['order_date', 'transaction_date', 'invoice_date', 'event_date', 'created_at'];

        // 1. First priority: Look for explicit transactional date columns in query tables
        foreach ($tables as $tbl) {
            $tblCols = $schemaTables[strtolower($tbl)] ?? [];
            foreach ($preferredDateCols as $prefCol) {
                if (in_array($prefCol, $tblCols, true)) {
                    $candidateTable = $tbl;
                    $candidateCol = $prefCol;
                    break 2;
                }
            }
        }

        // 2. Second priority: Any column with date/time in its name
        if (!$candidateCol) {
            foreach ($tables as $tbl) {
                $tblCols = $schemaTables[strtolower($tbl)] ?? [];
                foreach ($tblCols as $col) {
                    if (str_contains($col, 'date') || str_contains($col, 'time')) {
                        $candidateTable = $tbl;
                        $candidateCol = $col;
                        break 2;
                    }
                }
            }
        }

        // If no date column found, query is not filterable
        if (!$candidateCol || !$candidateTable) {
            return [
                'supported' => false,
                'source_table' => null,
                'date_column' => null,
                'table_or_alias' => null,
                'driver' => $driver,
                'reason' => 'No date column found in query tables (' . implode(', ', $tables) . ').',
            ];
        }

        // Determine if an alias was assigned to this candidate table in the SQL
        $tableOrAlias = $candidateTable;
        foreach ($aliases as $alias => $realTable) {
            if ($realTable === $candidateTable && $alias !== $candidateTable) {
                $tableOrAlias = $alias;
                break;
            }
        }

        return [
            'supported' => true,
            'source_table' => $candidateTable,
            'date_column' => $candidateCol,
            'table_or_alias' => $tableOrAlias,
            'driver' => $driver,
            'reason' => null,
        ];
    }

    /**
     * Apply date range filtering safely using parameterized prepared statement bindings.
     *
     * @param string $sql
     * @param array $compatibility
     * @param string $dateFrom
     * @param string $dateTo
     * @param string $driver
     * @return array{
     *     sql: string,
     *     bindings: list<mixed>,
     *     filter_applied: bool,
     *     filter_status: 'applied'|'unsupported'|'none',
     *     filter_message: string
     * }
     */
    public function applyDateFilter(
        string $sql,
        array $compatibility,
        string $dateFrom,
        string $dateTo,
        string $driver = 'mysql'
    ): array {
        if (!$compatibility['supported']) {
            return [
                'sql' => $sql,
                'bindings' => [],
                'filter_applied' => false,
                'filter_status' => 'unsupported',
                'filter_message' => $compatibility['reason'] ?? 'Date filter unavailable for this widget.',
            ];
        }

        $tableOrAlias = $compatibility['table_or_alias'];
        $dateColumn = $compatibility['date_column'];

        // Dialect-safe quoting
        $isPostgres = strtolower($driver) === 'pgsql';
        $quoteChar = $isPostgres ? '"' : '`';
        $qualifiedCol = "{$quoteChar}{$tableOrAlias}{$quoteChar}.{$quoteChar}{$dateColumn}{$quoteChar}";

        // Parameterized predicate using prepared statement placeholders
        $predicate = "{$qualifiedCol} >= ? AND {$qualifiedCol} <= ?";
        $bindings = [$dateFrom, $dateTo . ' 23:59:59'];

        $parameterizedSql = $this->injectWherePredicate($sql, $predicate);

        return [
            'sql' => $parameterizedSql,
            'bindings' => $bindings,
            'filter_applied' => true,
            'filter_status' => 'applied',
            'filter_message' => "Filtered: {$dateFrom} – {$dateTo}",
        ];
    }

    /**
     * Safely inject a WHERE predicate into a SELECT query.
     *
     * @param string $sql
     * @param string $predicate
     * @return string
     */
    protected function injectWherePredicate(string $sql, string $predicate): string
    {
        $trimmedSql = trim($sql);

        // Check if query already has a WHERE clause
        if (preg_match('/\bWHERE\b/i', $trimmedSql, $matches, PREG_OFFSET_CAPTURE)) {
            $whereOffset = $matches[0][1];
            $beforeWhere = substr($trimmedSql, 0, $whereOffset);
            $afterWhereKeyword = substr($trimmedSql, $whereOffset + strlen($matches[0][0]));

            // Find where existing WHERE ends (before GROUP BY, HAVING, ORDER BY, LIMIT, or end of string)
            $terminalPattern = '/\b(GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT)\b/i';
            if (preg_match($terminalPattern, $afterWhereKeyword, $termMatches, PREG_OFFSET_CAPTURE)) {
                $termOffset = $termMatches[0][1];
                $existingCondition = trim(substr($afterWhereKeyword, 0, $termOffset));
                $afterClause = substr($afterWhereKeyword, $termOffset);

                return $beforeWhere . "WHERE ({$existingCondition}) AND ({$predicate}) " . $afterClause;
            } else {
                $existingCondition = trim($afterWhereKeyword);
                return $beforeWhere . "WHERE ({$existingCondition}) AND ({$predicate})";
            }
        }

        // If NO existing WHERE clause: insert after FROM / JOIN and before GROUP BY, HAVING, ORDER BY, LIMIT
        $terminalPattern = '/\b(GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT)\b/i';
        if (preg_match($terminalPattern, $trimmedSql, $termMatches, PREG_OFFSET_CAPTURE)) {
            $termOffset = $termMatches[0][1];
            $beforeClause = rtrim(substr($trimmedSql, 0, $termOffset));
            $afterClause = substr($trimmedSql, $termOffset);

            return $beforeClause . " WHERE {$predicate} " . $afterClause;
        }

        // Otherwise append at the end
        return $trimmedSql . " WHERE {$predicate}";
    }
}
