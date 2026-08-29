<?php

namespace App\Services;

class SqlGuardrailService
{
    /**
     * Validate the safety of an AI-generated SQL query.
     *
     * @param string $sql
     * @return array{allowed: bool, reason: ?string}
     */
    public function validate(string $sql): array
    {
        $normalized = trim($sql);

        if (empty($normalized)) {
            return [
                'allowed' => false,
                'reason' => 'SQL query is empty.'
            ];
        }

        // 1. Must start with SELECT (case-insensitive)
        if (!preg_match('/^\s*select\b/i', $normalized)) {
            return [
                'allowed' => false,
                'reason' => 'Only SELECT queries are permitted.'
            ];
        }

        // 2. Reject multiple statements (semicolon followed by non-whitespace characters)
        if (preg_match('/;\s*\S+/', $normalized)) {
            return [
                'allowed' => false,
                'reason' => 'Multiple SQL statements are not allowed.'
            ];
        }

        // 3. Reject forbidden words on word boundaries (in query structure, excluding literal strings)
        $sqlWithoutStrings = preg_replace('/([\'"])(.*?)\1/', '', $normalized);
        $forbiddenKeywords = [
            'insert', 'update', 'delete', 'drop', 'alter', 'truncate', 
            'create', 'grant', 'revoke', 'replace', 'show', 'describe',
            'into', 'load', 'handler', 'lock', 'unlock', 'call'
        ];

        foreach ($forbiddenKeywords as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $sqlWithoutStrings)) {
                return [
                    'allowed' => false,
                    'reason' => "Forbidden operation detected: Use of '{$keyword}' keyword is restricted."
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null
        ];
    }
}
