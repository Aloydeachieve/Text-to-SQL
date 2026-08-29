<?php

namespace App\Services;

class DatabaseSchemaService
{
    /**
     * Get list of allowed tables and their columns.
     *
     * @return array<string, list<string>>
     */
    public function getTablesAndColumns(): array
    {
        return [
            'customers' => ['id', 'name', 'email', 'phone', 'city', 'created_at', 'updated_at'],
            'products' => ['id', 'name', 'category', 'price', 'stock', 'created_at', 'updated_at'],
            'orders' => ['id', 'customer_id', 'order_date', 'total_amount', 'created_at', 'updated_at'],
            'order_items' => ['id', 'order_id', 'product_id', 'quantity', 'unit_price', 'total_price', 'created_at', 'updated_at'],
            'query_logs' => ['id', 'question', 'generated_sql', 'passed_guardrails', 'execution_status', 'execution_time_ms', 'error_message', 'confidence_score', 'created_at', 'updated_at'],
        ];
    }

    /**
     * Get the textual schema context for AI prompts.
     */
    public function getPromptContext(): string
    {
        return <<<SCHEMA
- Table: customers
  Columns: id (bigint, PK), name (varchar), email (varchar, unique), phone (varchar, nullable), city (varchar), created_at (timestamp), updated_at (timestamp)
- Table: products
  Columns: id (bigint, PK), name (varchar), category (varchar), price (decimal 10,2), stock (int), created_at (timestamp), updated_at (timestamp)
- Table: orders
  Columns: id (bigint, PK), customer_id (bigint, FK to customers.id), order_date (date), total_amount (decimal 10,2), created_at (timestamp), updated_at (timestamp)
- Table: order_items
  Columns: id (bigint, PK), order_id (bigint, FK to orders.id), product_id (bigint, FK to products.id), quantity (int), unit_price (decimal 10,2), total_price (decimal 10,2), created_at (timestamp), updated_at (timestamp)

Relationships:
- orders.customer_id references customers.id
- order_items.order_id references orders.id
- order_items.product_id references products.id
SCHEMA;
    }

    /**
     * Get detailed schema metadata for tables and relationships.
     */
    public function getSchemaDetails(): array
    {
        return [
            'tables' => [
                [
                    'name' => 'customers',
                    'columns' => [
                        ['name' => 'id', 'type' => 'bigint (PK)'],
                        ['name' => 'name', 'type' => 'varchar'],
                        ['name' => 'email', 'type' => 'varchar (unique)'],
                        ['name' => 'phone', 'type' => 'varchar (nullable)'],
                        ['name' => 'city', 'type' => 'varchar'],
                        ['name' => 'created_at', 'type' => 'timestamp'],
                        ['name' => 'updated_at', 'type' => 'timestamp'],
                    ],
                ],
                [
                    'name' => 'products',
                    'columns' => [
                        ['name' => 'id', 'type' => 'bigint (PK)'],
                        ['name' => 'name', 'type' => 'varchar'],
                        ['name' => 'category', 'type' => 'varchar'],
                        ['name' => 'price', 'type' => 'decimal(10,2)'],
                        ['name' => 'stock', 'type' => 'integer'],
                        ['name' => 'created_at', 'type' => 'timestamp'],
                        ['name' => 'updated_at', 'type' => 'timestamp'],
                    ],
                ],
                [
                    'name' => 'orders',
                    'columns' => [
                        ['name' => 'id', 'type' => 'bigint (PK)'],
                        ['name' => 'customer_id', 'type' => 'bigint (FK)'],
                        ['name' => 'order_date', 'type' => 'date'],
                        ['name' => 'total_amount', 'type' => 'decimal(10,2)'],
                        ['name' => 'created_at', 'type' => 'timestamp'],
                        ['name' => 'updated_at', 'type' => 'timestamp'],
                    ],
                ],
                [
                    'name' => 'order_items',
                    'columns' => [
                        ['name' => 'id', 'type' => 'bigint (PK)'],
                        ['name' => 'order_id', 'type' => 'bigint (FK)'],
                        ['name' => 'product_id', 'type' => 'bigint (FK)'],
                        ['name' => 'quantity', 'type' => 'integer'],
                        ['name' => 'unit_price', 'type' => 'decimal(10,2)'],
                        ['name' => 'total_price', 'type' => 'decimal(10,2)'],
                        ['name' => 'created_at', 'type' => 'timestamp'],
                        ['name' => 'updated_at', 'type' => 'timestamp'],
                    ],
                ],
            ],
            'relationships' => [
                ['from' => 'orders.customer_id', 'to' => 'customers.id', 'label' => 'belongs to customer'],
                ['from' => 'order_items.order_id', 'to' => 'orders.id', 'label' => 'belongs to order'],
                ['from' => 'order_items.product_id', 'to' => 'products.id', 'label' => 'contains product'],
            ]
        ];
    }
}
