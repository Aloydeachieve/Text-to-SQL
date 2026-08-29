<?php

namespace App\Services\Ai;

use App\Services\Contracts\AiServiceInterface;

class RuleBasedAiService implements AiServiceInterface
{
    public function generateSql(string $question): AiSqlResponse
    {
        $q = strtolower(trim($question));

        // 1. "How many customers do we have?"
        if ($this->matchesKeywords($q, ['customer', 'how many']) || $this->matchesKeywords($q, ['customer', 'count'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT COUNT(*) AS total_customers FROM customers",
                confidence: 0.99,
                explanation: "Counts the total number of records in the customers table."
            );
        }

        // 4. "Which customers placed the most orders?" (check before Q5 because it overlaps keywords)
        if ($this->matchesKeywords($q, ['customer', 'most', 'order']) || $this->matchesKeywords($q, ['customer', 'top', 'order'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT c.id, c.name, c.city, COUNT(o.id) AS order_count, SUM(o.total_amount) AS total_spent FROM customers c JOIN orders o ON c.id = o.customer_id GROUP BY c.id, c.name, c.city ORDER BY order_count DESC LIMIT 5",
                confidence: 0.94,
                explanation: "Joins customers with orders, counts the number of orders per customer, sums the total spent, and returns the top 5 customers ordered by order count."
            );
        }

        // 2. "What are our top-selling products?"
        if ($this->matchesKeywords($q, ['product', 'top']) || $this->matchesKeywords($q, ['product', 'best']) || $this->matchesKeywords($q, ['product', 'popular'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT p.id, p.name, p.category, SUM(oi.quantity) AS total_sold, SUM(oi.total_price) AS total_revenue FROM products p JOIN order_items oi ON p.id = oi.product_id GROUP BY p.id, p.name, p.category ORDER BY total_sold DESC LIMIT 5",
                confidence: 0.95,
                explanation: "Joins order items with products, sums the quantities sold, groups by product, and returns the top 5 products ordered by units sold."
            );
        }

        // 3. "Show monthly revenue."
        if ($this->matchesKeywords($q, ['monthly', 'revenue']) || $this->matchesKeywords($q, ['month', 'revenue']) || $this->matchesKeywords($q, ['monthly', 'sales'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS revenue, COUNT(id) AS order_count FROM orders GROUP BY DATE_FORMAT(order_date, '%Y-%m') ORDER BY month ASC",
                confidence: 0.92,
                explanation: "Groups orders by their month of creation, aggregates the sum of their total amounts, and orders chronologically."
            );
        }

        // 5. "How many orders were placed this month?"
        if ($this->matchesKeywords($q, ['order', 'this month']) || $this->matchesKeywords($q, ['order', 'current month']) || $this->matchesKeywords($q, ['order', 'placed this month'])) {
            return new AiSqlResponse(
                success: true,
                sql: "SELECT COUNT(*) AS orders_this_month FROM orders WHERE order_date BETWEEN '2026-08-01' AND '2026-08-31'",
                confidence: 0.90,
                explanation: "Counts orders placed between August 1, 2026, and August 31, 2026."
            );
        }

        // Fallback for custom queries during Phase 1
        return new AiSqlResponse(
            success: false,
            error: "The local rule-based simulation driver only supports the 5 quick-start queries out-of-the-box. Please configure GEMINI_API_KEY in backend/.env to query arbitrary questions."
        );
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
