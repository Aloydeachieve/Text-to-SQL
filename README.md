# Text-to-SQL Interface with Guardrails

A portfolio-grade, full-stack AI engineering application that translates natural language business questions into SQL queries, executes them safely against a MySQL database using a rigorous security guardrail and schema validation pipeline, and renders interactive visualizations.

---

## 🌟 Engineering Value & Portfolio Readiness

This is not a simple wrapper around an LLM API. It demonstrates a production-hardened, secure, and resilient system pattern for exposing relational database assets to generative AI:

1. **LLM Output Structuring**: Enforces strict JSON schemas on generative completions (`sql`, `confidence`, `explanation`) using Gemini 1.5-flash options.
2. **Multi-Stage Security Gateways**: Separates query generation from query validation. AI outputs and user-edited queries must pass through independent validation layers *before* touching the database.
3. **Database-Aware Validation**: Inspects queries statically to verify referenced tables and columns exist, preventing syntax exception leakage and database crashes.
4. **Resiliency and Timeouts**: Connect timeouts (5s) and request timeouts (10s) prevent server connection exhaustion. Raw database trace exceptions are caught and generalized in production environments.
5. **Interactive Workspaces**: Allows developers/analysts to edit, re-validate, and re-execute queries while maintaining 100% of the guardrail protection.
6. **Zero-Dependency SVG Visualizations**: Dynamically checks results to render SVG-based distribution bars and chronological trend lines with tooltips, fully compatible with React 19 / Next.js 15.

---

## 🛡️ Why Text-to-SQL Needs Guardrails

Generative AI models are prone to hallucination, formatting variations, and instruction-drift. Letting an AI write and execute SQL against an database without guardrails exposes systems to:
- **Destructive Statements**: An AI might translate a prompt into a `DROP TABLE`, `TRUNCATE`, or `DELETE` statement.
- **SQL Injections**: A user could inject a prompt designed to append malicious subqueries (e.g. `'; DROP TABLE users--`).
- **Schema Conflicts**: Hallucinated table/column names cause database connection exceptions, which can expose structural names or paths to the frontend.
- **Denial of Service (DoS)**: Inefficient or massive Cartesian joins can lock up CPU and memory.

Our **Security Pipeline** prevents this by validating queries at the string, token, and database levels.

---

## 📊 Technical Architecture & System Pipelines

```mermaid
graph TD
    UserQuery([User Question]) --> AmbiguityCheck{Ambiguous Question?}
    AmbiguityCheck -- Yes --> Clarify[Return Clarification & Suggestion Chips]
    Clarify --> UI[Frontend Dashboard]
    AmbiguityCheck -- No --> Introspect[Dynamic Schema Introspection & System Table Exclusion]
    Introspect --> Relevance[Schema Relevance Engine & Graph Bridge Discovery]
    Relevance --> AI[Dialect-Aware AI SQL Generation]
    UserEdit([Manually Edited SQL]) --> Guard[1. SqlGuardrailService]
    AI --> Guard
    Guard -- Validate SELECT/Keywords --> SchemaVal[2. SqlSchemaValidator]
    SchemaVal -- Validation Failed (1st try) --> RetryCheck{Retry Attempted?}
    RetryCheck -- No --> RetryPrompt[Controlled SQL Regeneration with Feedback]
    RetryPrompt --> Guard
    RetryCheck -- Yes --> BlockSchema[Halt Execution & Report Schema Error]
    BlockSchema --> UI
    SchemaVal -- Verify Tables/Columns --> CheckSkip{Is Custom SQL?}
    CheckSkip -- Yes --> Executor[4. SqlExecutorService]
    CheckSkip -- No --> SemanticVal[3. SqlSemanticValidator]
    SemanticVal -- Mismatch / Failed --> Block[Block Execution & Expose Intent Mismatch]
    SemanticVal -- Intent Matches Query --> Executor
    Executor -- Execute SELECT --> DB[(MySQL / PostgreSQL / Demo DB)]
    Executor --> Log[Tenant-Isolated QueryLog Database]
    Executor --> UI
    Block --> Log
    Block --> UI
    UI --> Editor[SqlEditor.tsx]
    UI --> SchemaExplorer[SchemaExplorer.tsx with Search & Badges]
    SchemaExplorer --> API_Schema[GET /api/v1/database-connections/{id}/schema]
```

### 1. Question Ambiguity Intelligence (`QuestionAmbiguityService`)
- Detects underspecified, metric-vague, entity-vague, and period-vague questions before consuming LLM tokens.
- Returns structured HTTP 200 clarification responses with actionable suggestions, prompt clarification questions, and reasons.
- Frontend renders interactive clarification suggestion chips that users can click to execute immediate clarified inquiries.

### 2. Schema Relevance Engine & Graph Traversal (`SchemaRelevanceService`)
- **Normalized Schema**: Enriched column types, nullability, PK/FK flags, and explicit relationship maps.
- **System Table Exclusion**: Filters internal framework tables (`migrations`, `telescope_%`, `pulse_%`, etc.) and system schemas (`pg_catalog`, `information_schema`, etc.).
- **In-Memory Relational Graph**: Builds an in-memory graph from FK relationships and uses Breadth-First Search (BFS) to identify necessary bridge/junction tables (e.g., connecting `customers` and `products` through `orders` and `order_items`).
- **Large Schema Protection**: Configurable limits on tables, columns per table, and schema payload bytes (`config/schema.php`).
- **Fallback Guarantee**: Unmatchable questions gracefully fall back to the full normalized schema, ensuring queries never run against an empty schema context.

### 3. SQL Guardrails (`SqlGuardrailService`)
- **Select-Only Check**: Asserts query starts with `SELECT`.
- **Semicolon Check**: Blocks multiple statements.
- **Forbidden Keyword Audit**: Strips string literals and blocks `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `CREATE`, `GRANT`, `REVOKE`, `INTO`, `LOAD`, `REPLACE`, `SHOW`, `LOCK`.

### 4. Dialect-Aware Schema Validation (`SqlSchemaValidator`)
- Validates referenced tables and columns against active customer schema.
- Comprehensive dialect-specific function whitelists for MySQL (`DATE_FORMAT`, `DATEDIFF`, `NOW`, etc.) and PostgreSQL (`DATE_TRUNC`, `EXTRACT`, `TO_CHAR`, `AGE`, etc.).
- Pre-processes and masks SQL `EXTRACT(... FROM ...)` expressions so internal `FROM` keywords do not corrupt table identification.

### 5. Controlled SQL Regeneration Retry Strategy
- If initial generated SQL fails schema or semantic validation, the system executes **at most 1 controlled correction attempt**.
- Feeds the exact validation failure reason back to the LLM model to guide self-correction.
- Safely halts if the retry attempt also fails, preventing infinite loops or hallucination spirals.

### 6. Semantic Validation & Query Interpretation (`SqlSemanticValidator` & `QueryInterpretationService`)
- **Two-Tier Semantic Evaluation**: Deterministic structural intent check (grouping, aggregations, ordering, joins) and AI-assisted intent verification.
- **Deep Query Interpretation**:
  - **Tables Used**: Automatically extracts and maps all referenced tables.
  - **Joins**: Resolves aliases and outputs explicit join paths (e.g., `orders.id → order_items.order_id`).
  - **Relational Grain**: Computes hierarchical output grain along 1:N cardinalities (e.g., `Order → Order Item`, `Customer → Order`).
  - **Filters**: Captures WHERE predicates with resolved table identifiers (e.g., `orders.status = 'completed'`).
  - **Aggregations**: Extracts target expressions and functions (e.g., `SUM(orders.total_amount)`).
- **⚠ Potential Multiplication Risk Detection (Fan-Out / Chasm Trap)**:
  - Automatically identifies when aggregations (`SUM`, `AVG`) operate on parent-table columns across 1:N joined child tables (e.g., `SUM(orders.total)` joined to `order_items`).
  - Flags prominent warning callout (`order_items contains multiple rows per order.`) with metric inflation details and mitigation recommendations.
  - Available for both AI-generated queries and custom SQL executions.

### 7. Reusable Business Intelligence & Analytics Workspace (`SavedQueryController`)
- **Query Persistence**: Verified queries can be saved with custom names, descriptions, target database links, and visualization preferences (`bar`, `line`, `table`).
- **Zero-Bypass Re-Execution**: Saved queries are treated as reusable business artifacts, **never** as trusted execution bypasses. Re-running a query re-validates tenant authorization, database connection availability, SQL guardrails, and active schema validity (protecting against schema drift).
- **Deleted Connection Safety**: If a saved query's customer database connection is deleted, execution safely halts with `422: "This saved query's database connection is no longer available."` rather than falling back to demo data.
- **Audit Source Tracking**: Logs every execution to `query_logs` with `source: 'saved_query'` (distinguishing from `natural_language` and `custom_sql`).
- **Interactive UI**: Workspace provides instant tab navigation between Workspace and the Saved Query Library with multi-field search and connection filtering.

### 8. Dashboards & Multi-Tenant Visual Analytics (`DashboardController`)
- **Zero-Bypass Shared Pipeline**: Dashboards reference `SavedQuery` records rather than duplicating SQL. All widgets execute through `SavedQueryExecutionService` enforcing full guardrails, dynamic schema validation, and read-only executor defense-in-depth.
- **Strict Tenant Isolation**: Companies can only view, update, delete, and execute their own dashboards and widgets. Cross-tenant widget creation is rejected server-side.
- **Partial Failure Resilience**: Dashboards with multiple widgets handle failures independently. If one widget encounters a deleted connection or schema drift, it safely reports an isolated error state while remaining widgets render healthy data.
- **Dynamic Visualizations**: Widgets support live KPI/Metric displays for single numeric values, interactive SVG Bar Charts, SVG Line Charts, and scrollable Data Tables.
- **Execution Flow**:
```
User
  ↓
Dashboard
  ↓
Dashboard Widget
  ↓
Saved Query
  ↓
Database Connection
  ↓
Security Pipeline (Guardrails → Live Schema Validation → Read-Only Execution)
  ↓
Customer Database
  ↓
Results
  ↓
Visualization (Metric / Bar / Line / Table)
```

### 9. Dashboard Filters, Exports & Performance Optimization (`DashboardFilterService` & `DashboardCacheService`)
- **Global Date Filter Engine**:
  - Automatically resolves preset date bounds: `Today`, `Yesterday`, `Last 7 Days`, `Last 30 Days`, `This Month`, `Last Month`, `This Quarter`, and `Custom Range`.
  - Non-destructive query compatibility analysis checks source tables for date/timestamp columns (`order_date`, `created_at`, `transaction_date`, etc.).
  - Unsupported queries (e.g. compound `UNION` statements or queries without date columns) cleanly report `Date filter unavailable for this widget` and execute unmodified without failing the dashboard.
- **Safe Prepared Parameterization**:
  - Strictly avoids raw string replacement or string concatenation.
  - Wraps existing WHERE predicates: `WHERE (<existing>) AND (<predicate>)` and binds date values via PDO prepared statement placeholders (`?`).
- **Short-Lived Caching & Dual Refresh**:
  - 5-minute memory cache (TTL = 300s) with strict tenant isolation (`tts_dash:c_{company_id}:db_{conn}:sq_{id}:v_{version}:f_{filter_hash}`).
  - Fail-safe architecture: cache driver issues fail open to live execution without breaking the user experience.
  - Dual refresh controls: `Refresh` (cached) and `Force Fresh Data` (`bypass_cache: true`) for live database re-execution.
- **RFC 4180 CSV & Executive Summary Export**:
  - Streaming CSV export (`GET /api/v1/dashboards/{id}/export/csv`) respecting active filters and including Microsoft Excel UTF-8 BOM.
  - Automated spreadsheet formula injection defense: sanitizes cells starting with `=`, `+`, `-`, `@`, `\t`, `\r` by prefixing with `'`.
  - Executive summary JSON endpoint (`GET /api/v1/dashboards/{id}/export/summary`) and browser `Print / PDF` printable view.
- **Calculation Risk Warning Modal**:
  - Detects potential fan-out multiplication risks in widgets and provides on-click modal inspection with grain analysis, JOIN paths, and mitigation recommendations.

---

## 🛠️ Technology Stack
- **Backend**: Laravel 12, Eloquent ORM, PHPUnit.
- **Frontend**: Next.js 16 (App Router, Turbopack), TypeScript, Tailwind CSS v4.
- **Database**: MySQL 8.0.
- **AI Integrations**: Gemini 3.5-flash API via Server-Side HTTP Client.

---

## 📁 API Endpoints

### 1. Execute SQL / Question
- **Endpoint**: `POST /api/v1/query`
- **Body**:
  ```json
  {
    "question": "Show total sales by month.",
    "sql": "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS revenue FROM orders GROUP BY month"
  }
  ```
- **Response**:
  ```json
  {
    "question": "Show total sales by month.",
    "sql": "SELECT DATE_FORMAT(order_date, '%Y-%m') AS month, SUM(total_amount) AS revenue FROM orders GROUP BY month",
    "guardrails": { "allowed": true, "reason": null },
    "schema_validation": { "valid": true, "reason": null },
    "semantic_validation": {
      "valid": true,
      "score": 0.98,
      "reason": null,
      "interpretation": "Extracts year and month from orders, sums revenue, and groups chronologically by month.",
      "tables": ["orders"],
      "operations": ["SUM", "DATE_FORMAT", "GROUP BY"],
      "filters": [],
      "grouping": ["month"],
      "ordering": []
    },
    "execution": {
      "success": true,
      "error": null,
      "time_ms": 14.5,
      "results": [ { "month": "2026-08", "revenue": 1420.50 } ]
    },
    "confidence": 0.98,
    "explanation": "Extracts the year and month from order_date, sums the order amounts, and groups chronologically."
  }
  ```

### 2. Schema Explorer
- **Endpoint**: `GET /api/v1/schema`
- **Response**:
  ```json
  {
    "success": true,
    "data": {
      "tables": [
        {
          "name": "customers",
          "columns": [ { "name": "id", "type": "bigint (PK)" } ]
        }
      ],
      "relationships": [
        { "from": "orders.customer_id", "to": "customers.id", "label": "belongs to customer" }
      ]
    }
  }
  ```

### 3. Query History Logs
- **Endpoint**: `GET /api/v1/history`

### 4. Authentication (Sanctum)
- `POST /api/v1/auth/register` — Register tenant company & user
- `POST /api/v1/auth/login` — Sign in and obtain Sanctum bearer token
- `POST /api/v1/auth/logout` — Revoke token and clear session (authenticated)
- `GET  /api/v1/auth/me` — Get current user & company profile (authenticated)

### 5. Multi-Tenant Database Connections
- `POST /api/v1/database-connections/test` — Probe customer connection without persisting
- `GET  /api/v1/database-connections` — List all connections for authenticated tenant
- `POST /api/v1/database-connections` — Validate and store encrypted connection
- `GET  /api/v1/database-connections/{id}` — Get connection metadata (password hidden)
- `DELETE /api/v1/database-connections/{id}` — Purge and delete tenant connection
- `GET  /api/v1/database-connections/{id}/schema` — Dynamic schema introspection (MySQL & PostgreSQL)

### 6. Saved Queries & Analytics Workspace
- `GET    /api/v1/saved-queries` — List saved queries for tenant (supports `?search=` and `?database_connection_id=`)
- `POST   /api/v1/saved-queries` — Save verified query (with guardrail validation and database association)
- `GET    /api/v1/saved-queries/{id}` — Get single saved query details
- `PUT    /api/v1/saved-queries/{id}` — Update query metadata, visualization type, or SQL
- `DELETE /api/v1/saved-queries/{id}` — Delete saved query
- `POST   /api/v1/saved-queries/{id}/execute` — Re-execute saved query through full guardrails, schema validation, and customer DB execution

### 7. Dashboards & Analytics Widgets
- `GET    /api/v1/dashboards` — List company dashboards with widget counts (supports `?search=`)
- `POST   /api/v1/dashboards` — Create new dashboard for authenticated tenant
- `GET    /api/v1/dashboards/{id}` — Retrieve dashboard with eager-loaded widgets and saved queries
- `PUT    /api/v1/dashboards/{id}` — Update dashboard name/description
- `DELETE /api/v1/dashboards/{id}` — Delete dashboard and cascade-delete its widgets
- `POST   /api/v1/dashboards/{id}/widgets` — Add saved query as a widget (enforces tenant ownership)
- `PATCH  /api/v1/dashboards/{id}/widgets/{widgetId}` — Update widget title, visualization type, width, or position
- `DELETE /api/v1/dashboards/{id}/widgets/{widgetId}` — Remove widget from dashboard
- `POST   /api/v1/dashboards/{id}/execute` — Re-execute all dashboard widgets through zero-bypass security pipeline with partial failure resilience

---

## 🏢 Multi-Tenant SaaS & Security Architecture

### Clear Separation of Concerns:
- **Application Database (Internal)**:
  - Houses platform tables: `users`, `companies`, `database_connections`, `query_logs`.
  - Customer queries NEVER run against the application database.
- **Customer Database (External)**:
  - Isolated business data owned by the customer (MySQL or PostgreSQL).
  - Dynamically connected at runtime using tenant-scoped identifiers (`tenant_c{company_id}_db{connection_id}`).
  - Dynamic schema introspection queries `information_schema` to extract tables, columns, primary keys, and relationships into a normalized model.

### 🛡️ Concrete Security Protections:
1. **Tenant Isolation**: All database connection CRUD and introspection operations enforce company-level ownership checks server-side.
2. **Credential Encryption at Rest**: Passwords stored using AES-256 (`encrypted` cast). Passwords are never returned in JSON, never displayed in the frontend, never logged, and never included in Gemini prompts.
3. **Dedicated READ-ONLY User Recommendation**:
   > [!IMPORTANT]
   > For production deployments, customers should ALWAYS configure a dedicated database user with `SELECT` privileges only.
   > ```sql
   > CREATE USER 'tts_readonly'@'%' IDENTIFIED BY 'secure_password';
   > GRANT SELECT ON customer_db.* TO 'tts_readonly'@'%';
   > FLUSH PRIVILEGES;
   > ```
4. **Defense-in-Depth Guardrails**: Even if a customer mistakenly provides a write user, our `SqlGuardrailService` blocks all `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, and administrative statements before execution.
5. **Safe Error Sanitization**: Connection probes and executor exceptions are sanitized to avoid exposing raw PDO exceptions, network topology, or credentials.

---

## 👥 Team Collaboration, Roles & Resource Permissions (Phase 8)

The platform provides a comprehensive multi-tenant role-based access control (RBAC) and resource-sharing model that empowers organizations to collaborate securely.

### Role Permissions Matrix

| Capability | Admin | Analyst | Viewer |
| :--- | :---: | :---: | :---: |
| **Manage Team Members** (Invite, update roles, remove) | ✅ Full | ❌ View Directory | ❌ View Directory |
| **Manage Database Connections** (Add, edit, test, delete) | ✅ Full | ❌ None | ❌ None |
| **Use Connections for Querying** | ✅ Yes | ✅ Yes (credential-free) | ❌ Restricted |
| **Interactive Query Workspace** (Natural language & SQL) | ✅ Yes | ✅ Full (with guardrails) | ❌ Blocked |
| **Create Saved Queries & Dashboards** | ✅ Yes | ✅ Yes | ❌ View-only |
| **Set Resource Visibility** (`private` ↔ `company`) | ✅ Yes (owned assets) | ✅ Yes (owned assets) | ❌ None |
| **Edit/Delete Owned Assets** | ✅ Yes | ✅ Yes | ❌ None |
| **Edit/Delete Other Members' Assets** | ✅ Yes (Admin override) | ❌ Forbidden | ❌ Forbidden |
| **View & Execute Company-Shared Saved Queries** | ✅ Yes | ✅ Yes | ✅ Yes |
| **View & Execute Company-Shared Dashboards** | ✅ Yes | ✅ Yes | ✅ Yes |
| **Audit Log Tracking** | ✅ Logged | ✅ Logged | ✅ Logged |

### 🔒 Core Safety & Security Invariants

1. **Anti-Lockout Protection**: Admins cannot modify or demote their own role.
2. **Last Admin Preservation**: Companies must always maintain at least one active Admin; the last admin cannot be demoted or deleted.
3. **Asset Preservation on Member Removal**: When a team member leaves or is removed, their company-shared queries and dashboards are **preserved** (the foreign key `user_id` is set to `NULL` via `ON DELETE SET NULL`), preventing team-wide data loss.
4. **Tenant & Visibility Isolation**: Cross-company member manipulation is rejected with HTTP 404/403. Private assets belonging to other users are completely excluded from API listings (`SavedQuery::visibleTo`, `Dashboard::visibleTo`).
5. **Widget Injection Defense**: Dashboards cannot embed private queries authored by other users, maintaining privacy across individual analysts.
6. **Comprehensive Audit Trails**: Membership changes (`member_created`, `member_role_updated`, `member_removed`) and resource sharing actions (`saved_query_shared`, `dashboard_shared`) are persistently recorded in `audit_logs`.

---

## 🚀 Setup Instructions

### Backend (Laravel)
1. Navigate to `/backend`.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Copy `.env.example` to `.env` and set up database/CORS details:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=text_to_sql
   DB_USERNAME=root
   DB_PASSWORD=
   
   AI_PROVIDER=gemini
   GEMINI_API_KEY=your_gemini_api_key
   ALLOWED_CORS_ORIGINS=http://localhost:3000
   ```
4. Run migrations and seed data:
   ```bash
   php artisan migrate --seed
   ```
5. Run server:
   ```bash
   php artisan serve --port=8001
   ```

### Frontend (Next.js)
1. Navigate to `/frontend`.
2. Install dependencies:
   ```bash
   npm install
   ```
3. Create `.env.local` pointing to backend port 8001:
   ```env
   NEXT_PUBLIC_API_URL=http://localhost:8001/api/v1
   ```
4. Start dev server:
   ```bash
   npm run dev
   ```

---

## 🧪 Testing Verification

Run the comprehensive 122-point feature and unit test suite covering authentication, tenant isolation, roles & permissions, team collaboration, encrypted connections, dynamic schema introspection, SQL guardrails, schema validation, semantic validation, saved queries, dashboards, date filters, and partial failure handling:
```bash
php artisan test
```

To lint and compile the frontend:
```bash
npm run lint
npx tsc --noEmit
npm run build
```
