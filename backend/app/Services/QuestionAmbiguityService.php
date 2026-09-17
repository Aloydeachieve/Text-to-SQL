<?php

namespace App\Services;

class QuestionAmbiguityService
{
    /**
     * Known ambiguous question patterns with friendly clarification messages and actionable suggestions.
     *
     * @var list<array{pattern: string, clarification: string, suggestions: list<string>}>
     */
    protected array $ambiguousPatterns = [
        [
            'pattern' => '/\bhow\s+are\s+(?:our\s+)?sales\b/i',
            'clarification' => "The question is ambiguous: 'sales' could refer to total revenue, order volume, or product growth.",
            'suggestions' => [
                'Total revenue by month',
                'Total number of orders placed this month',
                'Top 5 products by revenue',
            ],
        ],
        [
            'pattern' => '/\b(?:what|who)\s+(?:are|is)\s+(?:our\s+)?best\s+customers?\b/i',
            'clarification' => "Customer ranking could be measured by order count or total lifetime spending.",
            'suggestions' => [
                'Which customers placed the most orders?',
                'Which customers spent the most money?',
                'Top 10 customers by total order value',
            ],
        ],
        [
            'pattern' => '/\bwhat\s+(?:performed|is\s+performing)\s+well\b/i',
            'clarification' => "Performance could mean highest product revenue or highest sales volume.",
            'suggestions' => [
                'Top selling products by revenue this month',
                'Top selling products by quantity ordered',
            ],
        ],
        [
            'pattern' => '/^\s*(?:show\s+me\s+data|analyze\s+data|give\s+me\s+data|explore\s+database)\s*$/i',
            'clarification' => "Please specify which records or metrics you would like to analyze.",
            'suggestions' => [
                'How many customers do we have?',
                'What is our total revenue by month?',
                'Recent orders list',
            ],
        ],
    ];

    /**
     * Check if a question is ambiguous.
     *
     * @param string $question
     * @param int|null $companyId
     * @return array{ambiguous: bool, clarification: ?string, suggestions: list<string>}
     */
    public function evaluateAmbiguity(string $question, ?int $companyId = null): array
    {
        $trimmed = trim($question);

        foreach ($this->ambiguousPatterns as $entry) {
            if (preg_match($entry['pattern'], $trimmed)) {
                return [
                    'ambiguous' => true,
                    'clarification' => $entry['clarification'],
                    'suggestions' => $entry['suggestions'],
                ];
            }
        }

        if ($companyId) {
            try {
                $conceptTerms = \App\Models\SemanticTerm::where('company_id', $companyId)
                    ->where('target_type', 'concept')
                    ->get();

                $lower = strtolower($trimmed);
                foreach ($conceptTerms as $term) {
                    if (str_contains($lower, strtolower($term->term))) {
                        return [
                            'ambiguous' => true,
                            'clarification' => $term->definition ?? "The term '{$term->term}' is ambiguous. Please specify which definition or metric you mean.",
                            'suggestions' => [
                                "Show {$term->term} summary",
                                "Explore {$term->term} details",
                            ],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Ignore DB error and proceed
            }
        }

        return [
            'ambiguous' => false,
            'clarification' => null,
            'suggestions' => [],
        ];
    }
}
