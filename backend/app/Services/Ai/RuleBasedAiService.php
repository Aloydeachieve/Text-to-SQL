<?php

namespace App\Services\Ai;

use App\Services\Contracts\AiServiceInterface;

class RuleBasedAiService implements AiServiceInterface
{
    public function generateSql(string $question, ?string $schemaContext = null, string $driver = 'mysql'): AiSqlResponse
    {
        $q = strtolower(trim($question));
        $isPgsql = in_array(strtolower($driver), ['pgsql', 'postgres', 'postgresql'], true);

        // 1. "How many customers do we have?"
        if ($this->matchesKeywords($q, ['customer', 'how many']) || $this->matchesKeywords($q, ['customer', 'count']) || $this->matchesKeywords($q, ['count', 'customer'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT COUNT(*) AS total_customers FROM customers",
                confidence: 0.99,
                explanation: "Counts the total number of records in the customers table."
            );
        }

        // 2. "Which customers placed the most orders?"
        if ($this->matchesKeywords($q, ['customer', 'most', 'order']) || $this->matchesKeywords($q, ['customer', 'top', 'order'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT c.id, c.name, c.city, COUNT(o.id) AS order_count, SUM(o.total_amount) AS total_spent FROM customers c JOIN orders o ON c.id = o.customer_id GROUP BY c.id, c.name, c.city ORDER BY order_count DESC LIMIT 5",
                confidence: 0.94,
                explanation: "Joins customers with orders, counts the number of orders per customer, sums the total spent, and returns the top 5 customers ordered by order count."
            );
        }

        // 3. "What are our top-selling products?"
        if ($this->matchesKeywords($q, ['product', 'top']) || $this->matchesKeywords($q, ['product', 'best']) || $this->matchesKeywords($q, ['product', 'popular']) || $this->matchesKeywords($q, ['top-selling', 'product'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT p.id, p.name, p.category, SUM(oi.quantity) AS total_sold, SUM(oi.total_price) AS total_revenue FROM products p JOIN order_items oi ON p.id = oi.product_id GROUP BY p.id, p.name, p.category ORDER BY total_sold DESC LIMIT 5",
                confidence: 0.95,
                explanation: "Joins order items with products, sums the quantities sold, groups by product, and returns the top 5 products ordered by units sold."
            );
        }

        // 4. "Show monthly revenue."
        if ($this->matchesKeywords($q, ['monthly', 'revenue']) || $this->matchesKeywords($q, ['month', 'revenue']) || $this->matchesKeywords($q, ['monthly', 'sales'])) {
            $sql = $isPgsql
                ? "SELECT DATE_TRUNC('month', order_date) AS month, SUM(total_amount) AS revenue, COUNT(id) AS order_count FROM orders GROUP BY DATE_TRUNC('month', order_date) ORDER BY month ASC"
                : "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS revenue, COUNT(id) AS order_count FROM orders GROUP BY DATE_FORMAT(order_date, '%Y-%m') ORDER BY month ASC";

            return new AiSqlResponse(
                success: true,
                sql: $sql,
                confidence: 0.92,
                explanation: "Groups orders by their month of creation, aggregates the sum of their total amounts, and orders chronologically."
            );
        }

        // 5. "How many orders were placed this month?"
        if ($this->matchesKeywords($q, ['order', 'this month']) || $this->matchesKeywords($q, ['order', 'current month']) || $this->matchesKeywords($q, ['order', 'placed this month'])) {
            $sql = $isPgsql
                ? "SELECT COUNT(*) AS orders_this_month FROM orders WHERE order_date >= CURRENT_DATE - INTERVAL '1 month'"
                : "SELECT COUNT(*) AS orders_this_month FROM orders WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";

            return new AiSqlResponse(
                success: true,
                sql: $sql,
                confidence: 0.90,
                explanation: "Counts orders placed in the current month."
            );
        }

        // Fallback for custom queries during testing/local
        return new AiSqlResponse(
            success: false,
            error: "The local rule-based simulation driver only supports predefined queries out-of-the-box. Please configure GEMINI_API_KEY in backend/.env to query arbitrary questions."
        );
    }

    /**
     * Regenerate SQL with correction feedback after a schema validation error.
     */
    public function generateSqlWithCorrection(string $question, ?string $schemaContext = null, string $driver = 'mysql', string $failedSql = '', string $errorMessage = ''): AiSqlResponse
    {
        // If the failed query had an invalid column, correct it based on the error
        if (!empty($failedSql) && str_contains($errorMessage, 'Unknown column reference')) {
            // E.g. replace product_name with name
            $correctedSql = str_ireplace('product_name', 'name', $failedSql);
            $correctedSql = str_ireplace('customer_name', 'name', $correctedSql);
            $correctedSql = str_ireplace('order_total', 'total_amount', $correctedSql);

            if ($correctedSql !== $failedSql) {
                return new AiSqlResponse(
                    success: true,
                    sql: $correctedSql,
                    confidence: 0.91,
                    explanation: "Corrected column references according to customer schema feedback."
                );
            }
        }

        // Default to standard generation
        return $this->generateSql($question, $schemaContext, $driver);
    }

    private function matchesKeywords(string $subject, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (strpos($subject, $keyword) === false) {
                return false;
            }
        }
        return true;
    }
}
