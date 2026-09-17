<?php

namespace App\Services;

use App\Models\SavedQuery;
use App\Models\SemanticMetric;
use App\Models\SemanticTableClassification;
use App\Models\SemanticTerm;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SemanticContextService
{
    /**
     * Build structured prompt context describing company metrics, terms, and table classifications.
     */
    public function getSemanticPromptContext(string $question, ?int $companyId, array $normalizedSchema = []): string
    {
        if (!$companyId) {
            return '';
        }

        $metrics = $this->findRelevantMetrics($question, $companyId);
        $classifications = $this->getTableClassifications($companyId);
        $terms = $this->getRelevantTerms($question, $companyId);

        $sections = [];

        // 1. Business Metrics Section
        if ($metrics->isNotEmpty()) {
            $metricLines = ["COMPANY CANONICAL BUSINESS METRICS (MANDATORY DEFINITIONS):"];
            $metricLines[] = "When answering questions referencing these business concepts, you MUST follow these canonical definitions strictly:";
            
            foreach ($metrics as $metric) {
                $lines = [
                    "- Metric: \"{$metric->name}\" (Slug: {$metric->slug})" . ($metric->is_source_of_truth ? " [CANONICAL SOURCE-OF-TRUTH]" : ""),
                    "  * Definition: {$metric->definition}",
                    "  * Canonical Source Table: `{$metric->source_table}`",
                    "  * Canonical Source Column: `{$metric->source_column}`",
                    "  * Required Aggregation: {$metric->aggregation}",
                ];

                if (!empty($metric->filter_condition)) {
                    $lines[] = "  * REQUIRED FILTER CONDITION: {$metric->filter_condition} (You MUST include this in the WHERE clause when calculating this metric)";
                }

                if (!empty($metric->date_column)) {
                    $lines[] = "  * Canonical Date Column: `{$metric->date_column}` (Use for time ranges, trends, and date filtering)";
                }

                $metricLines[] = implode("\n", $lines);
            }

            $sections[] = implode("\n", $metricLines);
        }

        // 2. Table Classifications & Source of Truth Section
        if (!empty($classifications)) {
            $classLines = ["TABLE CLASSIFICATIONS & USAGE POLICIES:"];
            $hasWarnings = false;

            foreach ($classifications as $table => $info) {
                $classification = strtolower($info['classification'] ?? 'business');
                $desc = $info['description'] ? " - {$info['description']}" : "";
                $pref = !empty($info['is_preferred_source']) ? " [PREFERRED SOURCE-OF-TRUTH for {$info['preferred_for_concept']}]" : "";

                if (in_array($classification, ['staging', 'archive', 'test', 'internal'])) {
                    $hasWarnings = true;
                    $classLines[] = "- Table `{$table}`: Classified as [{$classification}]{$pref}{$desc}. WARNING: Do NOT use this table for standard reporting, analytics, or metric calculations unless the user explicitly requests 'staging' or 'test' or 'archive' data.";
                } elseif (!empty($info['is_preferred_source'])) {
                    $classLines[] = "- Table `{$table}`: Classified as [{$classification}]{$pref}{$desc}. Preferred canonical source for business analytics.";
                }
            }

            if ($hasWarnings || count($classLines) > 1) {
                $sections[] = implode("\n", $classLines);
            }
        }

        // 3. Business Terminology Aliases Section
        if ($terms->isNotEmpty()) {
            $termLines = ["BUSINESS TERMINOLOGY MAPPINGS:"];
            foreach ($terms as $term) {
                $targetDesc = match ($term->target_type) {
                    'metric' => "Maps to Metric [{$term->metric?->name}]",
                    'table' => "Maps to Table `{$term->target_name}`",
                    'column' => "Maps to Column `{$term->target_name}`",
                    'filter' => "Requires Filter `{$term->target_name}`",
                    default => "Concept: {$term->target_name}",
                };
                $termLines[] = "- \"{$term->term}\" -> {$targetDesc}" . ($term->definition ? " ({$term->definition})" : "");
            }
            $sections[] = implode("\n", $termLines);
        }

        if (empty($sections)) {
            return '';
        }

        return "\n\n=== BUSINESS SEMANTIC LAYER INTELLIGENCE ===\n" . implode("\n\n", $sections) . "\n============================================\n";
    }

    /**
     * Find metrics that match the question or are active for the company.
     */
    public function findRelevantMetrics(string $question, ?int $companyId): Collection
    {
        if (!$companyId) {
            return collect();
        }

        $allMetrics = SemanticMetric::where('company_id', $companyId)
            ->active()
            ->get();

        if ($allMetrics->isEmpty()) {
            return collect();
        }

        $lowerQuestion = strtolower($question);
        $tokens = array_filter(preg_split('/[^a-z0-9_]+/i', $lowerQuestion) ?: []);

        // Also check mapped terms
        $mappedTerms = SemanticTerm::where('company_id', $companyId)
            ->whereNotNull('metric_id')
            ->get();

        $matchedMetricIds = [];

        foreach ($mappedTerms as $term) {
            $lowerTerm = strtolower($term->term);
            if (str_contains($lowerQuestion, $lowerTerm)) {
                $matchedMetricIds[] = $term->metric_id;
            }
        }

        $matched = $allMetrics->filter(function (SemanticMetric $metric) use ($lowerQuestion, $tokens, $matchedMetricIds) {
            if (in_array($metric->id, $matchedMetricIds, true)) {
                return true;
            }

            $name = strtolower($metric->name);
            $slug = strtolower($metric->slug);

            if (str_contains($lowerQuestion, $name) || str_contains($lowerQuestion, $slug)) {
                return true;
            }

            // Word token match
            foreach ($tokens as $token) {
                if (strlen($token) >= 3 && (str_contains($name, $token) || str_contains($slug, $token))) {
                    return true;
                }
            }

            return false;
        });

        // If no direct question match found, return all source-of-truth metrics (up to 5) so AI knows company baseline
        if ($matched->isEmpty()) {
            return $allMetrics->where('is_source_of_truth', true)->take(5);
        }

        return $matched->values();
    }

    /**
     * Get table classifications mapped by table name.
     */
    public function getTableClassifications(?int $companyId): array
    {
        if (!$companyId) {
            return [];
        }

        return SemanticTableClassification::where('company_id', $companyId)
            ->get()
            ->keyBy(fn($item) => strtolower($item->table_name))
            ->map(fn($item) => [
                'table_name' => $item->table_name,
                'classification' => $item->classification,
                'description' => $item->description,
                'is_preferred_source' => (bool)$item->is_preferred_source,
                'preferred_for_concept' => $item->preferred_for_concept,
            ])
            ->toArray();
    }

    /**
     * Get relevant semantic terms matching the question.
     */
    public function getRelevantTerms(string $question, ?int $companyId): Collection
    {
        if (!$companyId) {
            return collect();
        }

        $terms = SemanticTerm::where('company_id', $companyId)
            ->with('metric')
            ->get();

        $lowerQuestion = strtolower($question);

        return $terms->filter(function (SemanticTerm $t) use ($lowerQuestion) {
            return str_contains($lowerQuestion, strtolower($t->term));
        })->values();
    }

    /**
     * Construct lightweight data lineage graph for a metric or all metrics.
     *
     * Returns:
     * [
     *   'nodes' => [ ... ],
     *   'edges' => [ ... ]
     * ]
     */
    public function buildLineageGraph(?int $companyId, ?int $metricId = null, array $schema = []): array
    {
        if (!$companyId) {
            return ['nodes' => [], 'edges' => []];
        }

        $query = SemanticMetric::where('company_id', $companyId)->active();
        if ($metricId) {
            $query->where('id', $metricId);
        }

        $metrics = $query->get();
        $classifications = $this->getTableClassifications($companyId);
        $relationships = $schema['relationships'] ?? [];

        $nodes = [];
        $edges = [];
        $nodeIndex = [];

        $addNode = function (string $id, string $label, string $type, array $extra = []) use (&$nodes, &$nodeIndex) {
            if (!isset($nodeIndex[$id])) {
                $node = array_merge([
                    'id' => $id,
                    'label' => $label,
                    'type' => $type,
                ], $extra);
                $nodes[] = $node;
                $nodeIndex[$id] = true;
            }
        };

        $addEdge = function (string $source, string $target, string $label) use (&$edges) {
            $edges[] = [
                'source' => $source,
                'target' => $target,
                'label' => $label,
            ];
        };

        foreach ($metrics as $metric) {
            $metricNodeId = "metric_{$metric->id}";
            $tableNodeId = "table_{$metric->source_table}";
            $colNodeId = "col_{$metric->source_table}_{$metric->source_column}";

            $tableClass = $classifications[strtolower($metric->source_table)]['classification'] ?? 'business';

            // 1. Metric Node
            $addNode($metricNodeId, $metric->name, 'metric', [
                'aggregation' => $metric->aggregation,
                'definition' => $metric->definition,
                'is_source_of_truth' => $metric->is_source_of_truth,
            ]);

            // 2. Table Node
            $addNode($tableNodeId, $metric->source_table, 'table', [
                'classification' => $tableClass,
                'is_preferred_source' => $classifications[strtolower($metric->source_table)]['is_preferred_source'] ?? false,
            ]);

            // 3. Column Node
            $addNode($colNodeId, "{$metric->source_table}.{$metric->source_column}", 'column', [
                'column' => $metric->source_column,
                'table' => $metric->source_table,
                'aggregation' => $metric->aggregation,
            ]);

            // Metric -> Column -> Table edges
            $addEdge($metricNodeId, $colNodeId, "aggregates ({$metric->aggregation})");
            $addEdge($colNodeId, $tableNodeId, "belongs to");

            // 4. Filter Node (if defined)
            if (!empty($metric->filter_condition)) {
                $filterNodeId = "filter_{$metric->id}";
                $addNode($filterNodeId, $metric->filter_condition, 'filter', [
                    'condition' => $metric->filter_condition,
                ]);
                $addEdge($metricNodeId, $filterNodeId, "requires filter");
                $addEdge($filterNodeId, $tableNodeId, "applies to");
            }

            // 5. Date Column Node (if defined)
            if (!empty($metric->date_column)) {
                $dateColId = "col_{$metric->source_table}_{$metric->date_column}";
                $addNode($dateColId, "{$metric->source_table}.{$metric->date_column}", 'date_column', [
                    'column' => $metric->date_column,
                    'table' => $metric->source_table,
                ]);
                $addEdge($metricNodeId, $dateColId, "time grain");
                $addEdge($dateColId, $tableNodeId, "belongs to");
            }

            // 6. Connect to joined tables via schema foreign keys
            foreach ($relationships as $rel) {
                $fromTable = strtolower($rel['from_table'] ?? $rel['from'] ?? '');
                $toTable = strtolower($rel['to_table'] ?? $rel['to'] ?? '');
                $sourceLower = strtolower($metric->source_table);

                if ($fromTable === $sourceLower && !empty($toTable)) {
                    $joinedTableId = "table_{$toTable}";
                    $joinedClass = $classifications[$toTable]['classification'] ?? 'business';
                    $addNode($joinedTableId, $toTable, 'table', [
                        'classification' => $joinedClass,
                    ]);
                    $fkLabel = ($rel['from_column'] ?? '') . ' -> ' . ($rel['to_column'] ?? '');
                    $addEdge($tableNodeId, $joinedTableId, "joins on {$fkLabel}");
                }
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * Detect semantic drift between a SavedQuery's snapshot and the active Metric definition.
     */
    public function detectSemanticDrift(SavedQuery $savedQuery): ?array
    {
        if (!$savedQuery->metric_id) {
            return null;
        }

        $metric = SemanticMetric::find($savedQuery->metric_id);
        if (!$metric) {
            return [
                'has_drift' => true,
                'metric_id' => $savedQuery->metric_id,
                'metric_name' => 'Unknown (Deleted)',
                'reason' => 'The business metric originally associated with this query no longer exists.',
                'changes' => [],
            ];
        }

        $snapshot = $savedQuery->semantic_snapshot ?? [];
        if (empty($snapshot)) {
            return [
                'has_drift' => false,
                'metric_id' => $metric->id,
                'metric_name' => $metric->name,
                'changes' => [],
            ];
        }

        $changes = [];

        if (isset($snapshot['source_table']) && $snapshot['source_table'] !== $metric->source_table) {
            $changes[] = [
                'field' => 'source_table',
                'original' => $snapshot['source_table'],
                'current' => $metric->source_table,
            ];
        }

        if (isset($snapshot['source_column']) && $snapshot['source_column'] !== $metric->source_column) {
            $changes[] = [
                'field' => 'source_column',
                'original' => $snapshot['source_column'],
                'current' => $metric->source_column,
            ];
        }

        if (isset($snapshot['aggregation']) && $snapshot['aggregation'] !== $metric->aggregation) {
            $changes[] = [
                'field' => 'aggregation',
                'original' => $snapshot['aggregation'],
                'current' => $metric->aggregation,
            ];
        }

        if (isset($snapshot['filter_condition']) && $snapshot['filter_condition'] !== $metric->filter_condition) {
            $changes[] = [
                'field' => 'filter_condition',
                'original' => $snapshot['filter_condition'],
                'current' => $metric->filter_condition,
            ];
        }

        return [
            'has_drift' => !empty($changes),
            'metric_id' => $metric->id,
            'metric_name' => $metric->name,
            'changes' => $changes,
            'reason' => !empty($changes) ? 'The canonical business metric definition has changed since this query was saved.' : null,
        ];
    }
}
