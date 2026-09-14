# Changelog

All notable changes to the "Text-to-SQL Interface with Guardrails" project will be documented in this file.

## [0.14.0] - 2026-09-11
### Added (Phase 8 — Team Collaboration, Roles & Resource Permissions)
- **Three-Tier Role Hierarchy (`admin`, `analyst`, `viewer`)**:
  - Implemented core tenant roles on the `users` table (`role` enum: `admin`, `analyst`, `viewer`).
  - First registered user of any company is automatically assigned the `admin` role.
  - Model helpers: `$user->isAdmin()`, `$user->isAnalyst()`, `$user->isViewer()`.
  - Sanctum authentication and `/auth/me` updated to return user `role` and `company` metadata.
- **Company Team & Membership Engine (`CompanyMemberController`, `CompanyMemberPolicy`)**:
  - Administrative endpoints for team management under Sanctum auth (`/api/v1/company/members`).
  - Admins can invite team members with name, email, role, and optional password (defaults to secure random string).
  - Admins can update member roles or remove members.
  - Non-admins (analysts and viewers) receive a read-only directory of their colleagues.
  - **Safety Invariants**:
    - Admins cannot modify their own role (prevents accidental lockout).
    - Cannot demote or remove the last remaining admin in a company.
    - Strict company boundary defense: cross-company member lookup, updates, or deletions are rejected (404/403).
    - **Resource Preservation on Deletion**: Removing a user retains their created company-shared saved queries and dashboards by nullifying `user_id` (`ON DELETE SET NULL`), preventing catastrophic cascade deletion of company analytics assets.
- **Resource Visibility & Permissions Model (`SavedQueryPolicy`, `DashboardPolicy`)**:
  - Extended `saved_queries` and `dashboards` schemas with `visibility` (`'private'` or `'company'`).
  - Scopes: `SavedQuery::visibleTo($user)` and `Dashboard::visibleTo($user)` automatically filter private assets belonging to other users.
  - Fine-grained permission rules:
    - `view`: Permitted if user is the creator OR if visibility is `'company'`.
    - `update`/`delete`: Restricted strictly to resource owner (or tenant admin). Non-owners cannot edit or delete shared assets.
    - `execute`: Analysts can execute any visible query. Viewers can execute company-shared saved queries and dashboards, but are blocked from arbitrary workspace SQL.
  - Cross-tenant & cross-visibility widget injection defense: Dashboard widgets can only reference saved queries that are either company-shared or authored by the dashboard creator within the same company.
- **Database Connection Security (`DatabaseConnectionPolicy`)**:
  - Connection management (`index`, `store`, `destroy`, `test`, `schema`) restricted strictly to `admin` users.
  - Analysts and viewers can execute queries against tenant connections through authorized services without having direct credential access or management rights.
- **Query Execution Authorization (`QueryController`)**:
  - Restricted arbitrary SQL execution in the interactive query workspace to `admin` and `analyst` roles.
  - `viewer` role is barred from executing custom SQL; viewers safely execute authorized company-shared saved queries and dashboard widgets.
- **Audit Logging System (`AuditLog` Model & Migration)**:
  - Created `audit_logs` table (`company_id`, `user_id`, `event`, `auditable_type`, `auditable_id`, `metadata`, `ip_address`).
  - Automatically records team events: `member_created`, `member_role_updated`, `member_removed`.
  - Automatically records resource sharing events: `saved_query_shared`, `dashboard_shared`.
- **Frontend Collaboration & Permission UI**:
  - **Role & Visibility Badges**: Dedicated badges for `Admin` (purple/shield), `Analyst` (blue/trending), and `Viewer` (emerald/eye), plus `Private` (amber/lock) and `Company` (blue/globe).
  - **Team Management View (`TeamView.tsx` & `TeamMemberList.tsx`)**:
    - Organization header with company name, member count, and role summary cards.
    - Admin controls: "+ Add Member" modal, role select dropdown with self-role lockout, and member removal confirmation.
    - Non-admin directory view: Read-only table displaying team members and roles without administrative actions.
  - **Resource Sharing Controls (`ShareResourceModal.tsx`)**:
    - One-click modal to toggle between "Private (Only you)" and "Company Shared (All team members)".
    - Integrated into `SavedQueryList.tsx`, `DashboardList.tsx`, and `DashboardDetail.tsx`.
  - **Adaptive Header Navigation (`Header.tsx`)**:
    - Added "Team" navigation tab with team member count.
    - Displays current user name and role badge.
    - Hides "Databases" management button for non-admin users.
  - **Viewer Workspace Restrictions**:
    - Read-only SQL editor mode and banner notifications for viewers.
    - Prevents arbitrary SQL execution and hides dashboard widget creation buttons.
- **Automated Tests**:
  - Created comprehensive `TeamAndRoleTest.php` covering registration, login metadata, member CRUD, role invariants, resource preservation on deletion, cross-company rejection, visibility filtering, dashboard widget authorization, database connection permissions, and query execution authorization.
  - All 122 tests (1,291 assertions) passing with 0 failures or regressions.

## [0.13.0] - 2026-09-10
### Added (Phase 7 — Dashboard Filters, Exports & Performance)
- **Global Dashboard Date Filtering Engine (`DashboardFilterService.php`)**:
  - Implemented automatic resolution of standard date presets: `Today`, `Yesterday`, `Last 7 Days`, `Last 30 Days`, `This Month`, `Last Month`, `This Quarter`, and `Custom Range`.
  - Non-destructive query compatibility analysis: safely checks if referenced tables contain transactional date/timestamp columns (`order_date`, `created_at`, `transaction_date`, etc.).
  - Marks incompatible queries (e.g. compound `UNION` queries or queries without date columns) with friendly message (`filter_status: 'unsupported'`) and executes them unmodified without crashing.
  - Parameterized WHERE clause injection: strictly avoids raw string replace or concatenation; cleanly splits query into clauses, wraps existing WHERE conditions `WHERE (<existing>) AND (<predicate>)`, and binds date values as PDO prepared statements (`?`).
- **Short-Lived Dashboard Caching (`DashboardCacheService.php`)**:
  - 5-minute memory caching (TTL = 300s) for rapid dashboard loads.
  - Strict tenant isolation: cache keys are segregated by tenant company, connection key, saved query ID, version hash, and filter hash (`tts_dash:c_{company_id}:db_{conn}:sq_{id}:v_{version}:f_{filter_hash}`).
  - Fail-safe cache architecture (fail-open): cache failures gracefully fall back to live execution without breaking widget loading.
  - Dual refresh support: standard `Refresh` (cached) and `Force Fresh Data` (`bypass_cache: true`) for live database execution.
- **RFC 4180 CSV & Executive Summary Export (`DashboardController.php`)**:
  - Streamed CSV export endpoint (`GET /api/v1/dashboards/{id}/export/csv`):
    - Respects active global date filters.
    - Includes UTF-8 BOM for Microsoft Excel compatibility.
    - Formula injection defense: automatically neutralizes spreadsheet formula execution vectors (`=`, `+`, `-`, `@`, `\t`, `\r`) by prefixing with `'`.
    - Strict tenant boundary enforcement (returns 404 for cross-tenant export requests).
  - Executive summary export endpoint (`GET /api/v1/dashboards/{id}/export/summary`):
    - Returns structured JSON payload with dashboard metadata, active filter context, widget summary KPIs, and calculation risk indicators.
- **Deep Query Correctness & Multiplication Risk Preservation**:
  - All filtered widget executions strictly maintain pipeline validation (grain analysis, JOIN analysis, and fan-out multiplication risk detection).
- **Frontend Filter Bar, Status Badges & Transparency UI (`DashboardDetail.tsx`)**:
  - Top action bar with dual refresh (`Refresh` and `Force Fresh`), `Export CSV`, `Print / PDF`, and `Add Widget`.
  - Global Date Filter Bar with presets dropdown, custom date inputs, `Apply`, and `Reset` controls.
  - Widget-level badges: `✓ Filtered: <range>`, `⚠ Date filter unavailable`, and `⚡ Cached`.
  - Widget calculation risk warning badge (`⚠ Calculation risk detected`) with interactive modal displaying grain analysis, join structure, and fan-out inflation mitigation advice.
- **Automated Tests**:
  - Created `DashboardFiltersAndPerformanceTest.php` covering presets, custom dates, parameterized PDO bindings, UNION safety, invalid date handling, caching & bypass, tenant cache isolation, grain/fan-out preservation under filters, CSV export with formula sanitization, executive summary export, and cross-tenant export protection.

## [0.12.0] - 2026-09-09
### Added (Query Interpretation, Grain Analysis & Multiplication Risk Detection)
- **Deep Query Interpretation Service (`QueryInterpretationService.php`)**:
  - Implemented syntactic table alias extraction and resolution (`orders o JOIN order_items oi` -> `o` maps to `orders`, `oi` maps to `order_items`).
  - Implemented explicit join condition extraction with arrow notation formatting (`orders.id → order_items.order_id`).
  - Implemented aggregation expression extraction with resolved table attribution (e.g. `SUM(orders.total_amount)`).
  - Implemented relational query grain analysis deriving hierarchical grain (e.g. `Order → Order Item`, `Customer → Order`, `Customer (Grouped)`).
  - Implemented 1:N aggregation fan-out duplication protection (**Potential Multiplication Risk Detection**):
    - Automatically detects when queries aggregate parent-table metrics (such as `SUM(orders.total)`) across child tables with 1:N cardinality (`order_items`).
    - Produces clear, actionable warning: `"order_items contains multiple rows per order."` with details on fan-out metric inflation and mitigation advice.
- **Backend Semantic Pipeline & Custom SQL Integration**:
  - Integrated `QueryInterpretationService` into `SqlSemanticValidator.php`.
  - Updated `QueryController.php` to pass active schema relationships and compute structural interpretation for both AI-generated and custom SQL queries.
- **Frontend Query Interpretation UI (`QueryIntentCard.tsx`)**:
  - Structured card layout displaying **Tables used**, **Join**, **Grain**, **Filters**, and **Aggregation**.
  - Eye-catching amber callout banner for **⚠ Potential multiplication risk** displaying the warning, explanation, and recommendation.
- **Automated Tests**:
  - Created `QueryInterpretationTest.php` covering alias resolution, join extraction, grain derivation, multiplication risk triggering on parent column sums, risk suppression on child aggregations, and API response contracts.
  - Test suite passing: 99 tests (1,144 assertions).

## [0.11.0] - 2026-09-09
### Verification & Complete System Cross-Check (Phase 5)
- Conducted start-to-finish audit and cross-check of Phase 5 against all 25 specification sections:
  - Persistent `Dashboard` and `DashboardWidget` models and migrations with foreign key cascading.
  - Multi-tenant boundary defense in `DashboardController` (strictly blocking cross-tenant dashboards, widgets, saved queries, and execution).
  - Reusable zero-bypass security pipeline in `SavedQueryExecutionService` with `source: 'dashboard'` audit logging.
  - Partial failure resilience: healthy widgets execute normally while failing widgets safely report user-friendly errors without leaking credentials or database connection details.
  - Frontend responsiveness, SVG charts (bar, line), KPI metrics, data tables, width toggling, visualization switching, and direct "View Query" workspace navigation.
  - Saved query integration: dashboard reference counters displayed in the Saved Query library.
- Verified test suite and build stability:
  - `php artisan test`: **91 passing tests (1,102 assertions)**.
  - `npm run lint`: **0 errors, 0 warnings**.
  - `npx tsc --noEmit`: **0 TypeScript errors**.
  - `npm run build`: **Successful production build**.

### Added (Phase 5 Dashboards & Reusable Business Analytics)
- **Persistent Dashboard & DashboardWidget Data Models**:
  - Created `Dashboard` model (`backend/app/Models/Dashboard.php`) and migration `2026_09_08_010001_create_dashboards_table.php` (`company_id`, `user_id`, `name`, `description`).
  - Created `DashboardWidget` model (`backend/app/Models/DashboardWidget.php`) and migration `2026_09_08_010002_create_dashboard_widgets_table.php` (`dashboard_id`, `saved_query_id`, `title`, `visualization_type`, `position`, `width`, `height`).
  - Enriched relationships across `Company.php` (`dashboards(): HasMany`), `User.php` (`dashboards(): HasMany`), and `SavedQuery.php` (`dashboardWidgets(): HasMany`).
- **Centralized Zero-Bypass Saved Query Execution Service (`SavedQueryExecutionService`)**:
  - Extracted and centralized the shared zero-bypass execution pipeline: tenant ownership checks, connection availability checks, SQL guardrails, dynamic schema introspection, active schema validation, read-only query execution, guaranteed connection cleanup (`purgeConnection`), and query audit logging.
  - Refactored `SavedQueryController::execute` to use `SavedQueryExecutionService` with 100% backward compatibility.
- **Tenant-Safe Dashboard & Widget API Endpoints (`DashboardController`)**:
  - `GET /api/v1/dashboards`: lists company dashboards with eager-loaded user and widget counts.
  - `POST /api/v1/dashboards`: creates new dashboard for company.
  - `GET /api/v1/dashboards/{id}`: tenant-scoped retrieval with eager-loaded widgets and saved queries.
  - `PUT/PATCH /api/v1/dashboards/{id}`: updates dashboard name/description.
  - `DELETE /api/v1/dashboards/{id}`: cascade deletes dashboard and its widgets.
  - `POST /api/v1/dashboards/{id}/widgets`: adds saved query as a widget, strictly verifying saved query belongs to the same tenant company.
  - `PATCH /api/v1/dashboards/{id}/widgets/{widgetId}`: updates widget title, visualization type, width, or position.
  - `DELETE /api/v1/dashboards/{id}/widgets/{widgetId}`: removes widget from dashboard.
  - `POST /api/v1/dashboards/{id}/execute`: re-executes all widgets on a dashboard with `source: 'dashboard'` in `query_logs`.
- **Partial Failure Resilience**:
  - If a widget's database connection is unavailable or schema has drifted, the widget safely reports an isolated error state without crashing the dashboard. Remaining healthy widgets continue to execute and render.
- **Frontend Dashboards & Visual Analytics Workspace**:
  - `Header.tsx`: Added `Dashboards` navigation tab with live badge counters.
  - `DashboardList.tsx`: Responsive dashboard directory with instant search, widget counters, and CRUD actions.
  - `DashboardDetail.tsx`: Live analytics dashboard with responsive grid layout (1-column half-width and 2-column full-width widgets), live Refresh action, and seamless "View Query" links transitioning back to Workspace.
  - Visualization support for KPI/Metric cards (big bold numbers with automatic currency and number formatting), responsive SVG Bar Charts, responsive SVG Line Charts, and scrollable Data Tables.
  - `CreateDashboardModal.tsx`: Glassmorphism modal for creating and editing dashboards.
  - `AddWidgetModal.tsx`: Visual widget configurator for selecting saved queries, setting custom titles, choosing visualization types, and configuring grid width.
  - `SavedQueryList.tsx`: Enhanced to display "Used in X dashboards" badge when referenced by widgets.
- **Testing & Verification**:
  - Created `DashboardTest.php` with 15 comprehensive feature tests covering unauthenticated rejection, tenant isolation, cross-tenant widget rejection, partial failure resilience, credential privacy, and demo mode.
  - 100% backend test pass rate: 91 tests passing (1102 assertions).
  - Clean frontend verification: 0 ESLint errors/warnings, 0 TypeScript errors (`npx tsc --noEmit`), and successful Next.js 16 production build (`npm run build`).

## [0.10.0] - 2026-09-07
### Added (Phase 4 Analytics Workspace, Saved Queries & Reusable Business Intelligence)
- **Persistent SavedQuery Data Model & Database Migration**:
  - Created `SavedQuery` model (`backend/app/Models/SavedQuery.php`) and migration `2026_09_07_010001_create_saved_queries_table.php`.
  - Captures `company_id`, `user_id`, `database_connection_id`, `target_database_name`, `is_demo`, `name`, `description`, `natural_language_question`, `sql`, `dialect`, and `result_visualization_type`.
  - Added relationships in `Company.php` (`savedQueries(): HasMany`) and `User.php` (`savedQueries(): HasMany`).
- **Audit Source Tracking in Query Logs**:
  - Migration `2026_09_07_010002_add_source_to_query_logs_table.php` added `source` (`natural_language`, `custom_sql`, `saved_query`) to `query_logs`.
  - Updated `QueryLog.php` mass-assignment and `QueryController.php` audit logging across all execution and failure paths.
- **Tenant-Safe Saved Query CRUD & Security API Endpoints**:
  - Created `SavedQueryController.php` exposing:
    - `GET /api/v1/saved-queries`: lists company's saved queries with eager-loaded user/connection, search filter (`?search=`), and database connection filter (`?database_connection_id=`).
    - `POST /api/v1/saved-queries`: validates input, verifies database connection ownership, validates SQL against security guardrails, and stores metadata.
    - `GET /api/v1/saved-queries/{id}`: tenant-scoped retrieval.
    - `PUT/PATCH /api/v1/saved-queries/{id}`: tenant-scoped metadata and SQL update with guardrail checks.
    - `DELETE /api/v1/saved-queries/{id}`: tenant-scoped deletion.
    - `POST /api/v1/saved-queries/{id}/execute`: re-executes saved query through the full security pipeline.
- **Security & Schema Drift Protection on Re-Execution**:
  - Re-running saved queries strictly validates tenant ownership, database connection availability, SQL guardrails (`SELECT`-only, forbidden keyword audit), and active schema validation (defends against dropped or renamed columns).
  - Deleted or missing database connections halt safely with `422: "This saved query's database connection is no longer available."` without silently falling back to the demo database.
  - Dynamically established runtime connections are guaranteed to be cleaned up via `purgeConnection` in `finally` blocks.
  - Database credentials are never stored in `saved_queries`, never exposed in API responses, and never logged.
- **Frontend Analytics Workspace & Saved Queries Library**:
  - `Header.tsx`: Added navigation tabs for `Workspace` and `Saved Queries` (with real-time count badge).
  - `SaveQueryModal.tsx`: Glassmorphism modal capturing query name, description, target connection, visualization preference, and safe SQL preview.
  - `EditSavedQueryModal.tsx`: In-library editor for query metadata and visualization preference.
  - `SavedQueryList.tsx`: Reusable Business Intelligence library with instant multi-attribute search, database connection filter, status badges (`Demo DB`, `Connected`, `Connection Unavailable`), formatted SQL snippets, and `Open in Workspace` / `Run Query` actions.
  - `ChartResult.tsx`: Added interactive visualization preference switcher (`Bar`, `Line`, `Table Only`), restoring saved preferences on query re-execution.
  - `page.tsx`: Seamlessly integrates Workspace and Saved Queries views, provides "Save Query" actions on successful results and history items, and restores target database context upon opening.
- **Comprehensive Automated Testing & Verification**:
  - Created `SavedQueryTest.php` with 14 comprehensive feature tests covering unauthenticated rejection, tenant isolation, cross-tenant protection, guardrails, search/filtering, execution pipeline, deleted connection handling, schema drift validation, credential safety, and demo mode compatibility.
  - 100% test pass rate: 76 tests passing (998 assertions) across entire Laravel test suite.
  - Verified 0 TypeScript errors (`npx tsc --noEmit`) and clean Next.js 16 production build (`npm run build`).

## [0.9.0] - 2026-09-06
### Added (Phase 3 Production-Grade Customer Schema Intelligence & Query Reliability)
- **Full Normalized Schema Representation**:
  - Unified metadata format across MySQL, PostgreSQL, and Demo SQLite/MySQL databases.
  - Columns enriched with `primary` (boolean), `foreign` (boolean), `nullable` (boolean), `referenced_table` (?string), and `referenced_column` (?string).
  - Explicit relationships array with `from_table`, `from_column`, `to_table`, `to_column`, and descriptive `label`.
- **System & Framework Table Exclusion**:
  - Excluded internal system schemas: `information_schema`, `sys`, `mysql`, `performance_schema`, `pg_catalog`, `pg_toast`, `spatial_ref_sys`, `pg_stat_*`.
  - Excluded internal application tables: `migrations`, `personal_access_tokens`, `failed_jobs`, `password_resets`, `sessions`, `cache`, `telescope_*`, `pulse_*`.
- **In-Memory Schema Relevance Engine (`SchemaRelevanceService`)**:
  - Multi-tier scoring combining direct token matching, column matching (excluding `_id` foreign keys to avoid false positive endpoints), and curated business terminology mappings.
  - In-memory relational graph traversal (BFS shortest path) that automatically discovers bridge/junction tables (e.g. `orders` and `order_items` connecting `customers` and `products`).
  - Large schema protection: configurable caps on `max_tables`, `max_columns_per_table`, and `max_schema_bytes` (`config/schema.php`).
  - Fallback safety: unmatchable queries fall back gracefully to the full normalized schema, guaranteeing the AI prompt context is never empty.
- **Question Ambiguity Service (`QuestionAmbiguityService`)**:
  - Identifies underspecified questions lacking explicit metrics, timeframes, or entity scopes (e.g., *"Show my best data"*, *"Top performance"*).
  - Returns structured 200 clarification responses with `requires_clarification: true`, clarification question, reason, and clickable suggestion chips.
- **Controlled SQL Regeneration Retry Strategy**:
  - Added `generateSqlWithCorrection` to `AiServiceInterface`, `AiSqlManager`, `GeminiAiService`, and `RuleBasedAiService`.
  - Maximum 1 controlled correction attempt if initial SQL fails schema or semantic validation.
  - Injects specific validation failure feedback into prompt context without exposing database credentials or server paths.
  - Halts execution safely if the retry fails, preventing cascading retries or infinite loops.
- **Dialect-Aware Date & Scalar Function Intelligence**:
  - Expanded `SqlSchemaValidator` and `SqlSemanticValidator` function whitelists for MySQL (`DATE_FORMAT`, `DATEDIFF`, `CURDATE`, `NOW`, etc.) and PostgreSQL (`DATE_TRUNC`, `EXTRACT`, `TO_CHAR`, `AGE`, `NOW`, etc.).
  - Added regex masking for `EXTRACT(... FROM ...)` expressions so internal `FROM` keywords do not corrupt table identification.
  - Enhanced Gemini and Rule-Based system instructions with dialect-specific date formatting guidance.
- **Frontend Schema Explorer & Ambiguity Clarification UI**:
  - `SchemaExplorer.tsx`: Instant client-side table & column filter with matching counters, expand/collapse all buttons, and clean keyboard navigation.
  - `TableSchema.tsx`: Sleek badges for `PK`, `FK` (with reference destination tags), and `NULL` / `NOT NULL`.
  - `QueryIntentCard.tsx`: Glassmorphism clarification card with clickable suggestions that automatically re-run queries, subset schema tags, and intent explanation.
- **Verification & Parity**:
  - Added `SchemaIntelligenceTest.php` with 13 comprehensive feature tests (350 assertions).
  - 100% test pass rate across 62 backend tests (904 assertions).
  - 0 ESLint errors/warnings, 0 TypeScript errors (`npx tsc --noEmit`), and clean Next.js 16 production build.

## [0.8.0] - 2026-09-04
### Added (Phase 2 Customer Database Query Execution & Dynamic Schema Pipeline)
- **Active Database Context & Dynamic Schema Pipeline**:
  - **Customer Database Query Execution**:
    - Integrated `DatabaseConnectionManager` and `SchemaIntrospectionService` into `QueryController.php`.
    - Extended query endpoint `POST /api/v1/query` with optional `database_connection_id` payload parameter.
    - Enforced strict tenant authorization: users can only query database connections belonging to their company (401 for unauthenticated requests, 404 for cross-tenant attempts).
    - Established runtime connections dynamically (`tenant_c{company_id}_db{connection_id}`) and guaranteed cleanup via `purgeConnection` in `finally` blocks.
  - **Dynamic Schema Introspection & Context Passing**:
    - Customer database schema is dynamically introspected at query time using `SchemaIntrospectionService`.
    - Normalized tables, columns, data types, and foreign-key relationships are supplied as prompt context to `GeminiAiService`.
    - AI SQL generator dynamically customizes prompt instructions based on the customer database dialect (`MySQL` or `PostgreSQL`).
  - **Dynamic Schema & Semantic Validation**:
    - Updated `SqlSchemaValidator.php` to accept dynamic customer schema mappings.
    - **Step 17 Fix**: Added comprehensive SQL keywords, date/time, and scalar functions (`CURRENT_DATE`, `CURRENT_TIME`, `CURRENT_TIMESTAMP`, `NOW`, `CURDATE`, `DATEDIFF`, `DATE_SUB`, `DATE_ADD`, `EXTRACT`, `CAST`, `CONVERT`, `TRIM`, `LOWER`, `UPPER`, `SUBSTRING`, `COALESCE`, `ROUND`, `FLOOR`, `CEIL`, `ABS`, `CASE`, `WHEN`, `THEN`, `ELSE`, `END`, etc.) so valid SQL expressions are never rejected as unknown database columns.
    - Updated `SqlSemanticValidator.php` to accept customer schema and bypass demo-specific checks when custom customer tables are queried.
  - **Defense-in-Depth Read-Only Security**:
    - Enforced regex check in `SqlExecutorService.php` strictly requiring queries to start with `SELECT` or `WITH`.
    - Maintained guardrail security layer rejecting all DDL and DML operations (`DROP`, `DELETE`, `UPDATE`, `ALTER`, etc.).
  - **Audit Logging & Tenant Isolation in Query Logs**:
    - Migration `2026_09_04_030001_add_tenant_and_connection_to_query_logs_table.php` added nullable `user_id`, `company_id`, and `database_connection_id` with composite indexes.
    - Configured Eloquent `$casts` on `QueryLog` for boolean `passed_guardrails` and float `confidence_score` / `execution_time_ms`.
    - `QueryLog` records all customer queries with full tenant attribution and execution timing.
    - `HistoryController.php` filters query logs to the authenticated user's company plus public demo queries, preventing cross-tenant leakage.
  - **Frontend Active Database Switching & Dynamic Schema Explorer**:
    - Added sleek "Active Target Database" selector in dashboard header, enabling users to toggle seamlessly between Demo Database (MySQL Seeded) and connected customer databases.
    - Connected `selectedDatabaseId` to `submitQuery`, custom SQL re-execution, and `SchemaExplorer`.
    - Enhanced `SchemaExplorer.tsx` to dynamically fetch and display introspected customer database tables, columns, types, and primary/foreign keys.
  - **Zero Regressions & Comprehensive Verification**:
    - Created `CustomerDatabaseQueryTest.php` with 10 comprehensive feature tests covering unauthenticated rejection, cross-tenant isolation, customer DB execution, custom SQL, guardrails, executor defense-in-depth, Step 17 keyword fixes, demo backward-compatibility, history isolation, and credential redaction.
    - Verified 100% test pass rate across entire backend test suite (49 feature tests).
    - Verified clean Next.js production build and 0 ESLint warnings/errors.

## [0.7.0] - 2026-09-04
### Added (Phase 1 Multi-Tenant SaaS Evolution)
- **Multi-Tenant Architecture & Company Isolation**:
  - Created `Company` model (`backend/app/Models/Company.php`) and associated migration (`create_companies_table`).
  - Added `company_id` foreign key relation to `users` table (`add_company_id_to_users_table`).
  - Implemented strict tenant scoping: users can only view, test, introspect, and delete database connections belonging to their registered company.
- **Lightweight API Authentication (Laravel Sanctum)**:
  - Created `AuthController.php` exposing standard REST endpoints:
    - `POST /api/v1/auth/register` (creates tenant company, initial user, hashes password, returns bearer token).
    - `POST /api/v1/auth/login` (verifies credentials, issues bearer token).
    - `POST /api/v1/auth/logout` (revokes current access token, clears guard memory).
    - `GET /api/v1/auth/me` (returns safe authenticated user profile with tenant company metadata).
- **Secure Customer Database Connection Management**:
  - Created `DatabaseConnection` model (`backend/app/Models/DatabaseConnection.php`) and migration (`create_database_connections_table`).
  - Strict validation supporting `mysql` and `pgsql` drivers with port ranges and host checks. Arbitrary/unsupported drivers are rejected.
  - **Credential Security & Encryption at Rest**:
    - Passwords are automatically encrypted at rest using Laravel's `encrypted` attribute casting (AES-256-CBC).
    - Excluded from array and JSON serialization (`protected $hidden = ['password']`).
    - Database passwords are never logged, never exposed to Gemini prompts, never returned in API responses, and never transmitted back to the frontend.
- **Dynamic Database Connection Service**:
  - Created `DatabaseConnectionManager.php` responsible for dynamically establishing customer database connections at runtime without modifying the application's primary connection.
  - Generates isolated runtime connection keys (`tenant_c{company_id}_db{connection_id}`) with strict 5-second socket timeouts.
  - Ensures clean runtime memory purging (`purgeConnection`) upon completion or deletion.
- **Safe Test Connection API**:
  - Created `POST /api/v1/database-connections/test` enabling live connectivity testing without persisting invalid entries.
  - Catches raw PDO/network exceptions and sanitizes output to generic safe messages, preventing stack trace or credential leakage.
- **Tenant-Scoped Connection CRUD**:
  - Created `DatabaseConnectionController.php`:
    - `GET /api/v1/database-connections` (lists company's connections).
    - `POST /api/v1/database-connections` (tests and persists connection).
    - `GET /api/v1/database-connections/{id}` (inspects connection metadata).
    - `DELETE /api/v1/database-connections/{id}` (purges runtime configuration and deletes record).
- **Dynamic Schema Introspection Engine**:
  - Created `SchemaIntrospectionService.php` providing dynamic introspection of customer databases:
    - MySQL introspection via `information_schema` tables, columns, primary keys, and foreign keys.
    - PostgreSQL introspection via `information_schema` and constraint usage mappings.
    - Robust case-insensitive column casing normalization (`array_change_key_case`).
    - Standardized internal schema representation consumable by future AI prompts and visual explorers.
  - Created `GET /api/v1/database-connections/{id}/schema` endpoint to introspect authorized tenant databases.
- **Frontend Database Connection Management UI**:
  - Created `DatabaseConnectionModal.tsx` matching the application's glassmorphism dark aesthetic:
    - Seamless company registration and login flow for unauthenticated visitors with `localStorage` token storage.
    - "+ Connect Database" modal with driver selector (MySQL / PostgreSQL) and automatic port defaults (3306 / 5432).
    - Real-time "Test Connection" button with loading spinners, error alerts, and success banners.
    - Connected database listing displaying live status badges (`connected`, `failed`, `untested`), driver tags, host, and probe timestamps.
    - Inline dynamic Schema Explorer inspecting introspected customer tables, columns, and types.
    - Safe deletion workflows with confirmation modals.
  - Updated `Header.tsx` to display active company badge and "Databases" / "Connect Database" trigger.
  - Expanded `frontend/app/services/api.ts` with typed auth and database connection services.
- **Non-Breaking Pipeline Preservation**:
  - The complete demo pipeline (Gemini/local AI → Guardrails → Schema Validator → Semantic Validator → Executor → Charts) remains 100% active and backwards-compatible.
- **Testing & Verification**:
  - Created `AuthenticationTest.php` with 6 comprehensive feature tests.
  - Created `TenantDatabaseConnectionTest.php` with 8 comprehensive feature tests asserting encryption at rest, driver validation, tenant access control, cross-tenant deletion prevention, test probe safety, and normalized schema introspection.
  - Verified 100% passing test suite across all 39 backend tests (592 assertions).
  - Verified frontend clean build, 0 ESLint errors, and 0 TypeScript errors.

## [0.6.0] - 2026-09-04
### Added (Phase 6)
- **Semantic Validation & Query Intent Verification Layer**:
  - Created `SqlSemanticValidator.php` providing two-tier semantic analysis:
    - Deterministic structural extraction and analysis (tables, aggregate functions, grouping, ordering, filters, and joins).
    - AI-assisted verification via Gemini (`verifyIntent` in `GeminiAiService.php`) with strict JSON schema parsing and fail-closed timeout/error handling.
  - Detects queries that are syntactically safe and schema-valid but answer a different question than asked (e.g. answering customer ranking inquiries with overall un-grouped order totals).
  - Blocks database execution when an intent mismatch is detected, logging `execution_status = 'blocked'` in query history.
- **Pipeline Integration**:
  - Integrated `SqlSemanticValidator` into `QueryController.php` situated after `SqlSchemaValidator` and before `SqlExecutorService`.
  - Maintained safe bypass for manually authored custom SQL queries while keeping guardrails and schema validation strictly enforced.
- **Frontend Query Transparency**:
  - Created `QueryIntentCard.tsx` displaying the AI's natural-language interpretation of the question, touched tables, detected operations, filters, grouping, ordering, and match confidence score.
  - Implemented prominent warning and execution blocked alerts on intent mismatches (`⚠ QUERY INTENT MISMATCH`), highlighting why the query was blocked.
  - Updated `api.ts` with `SemanticValidationInfo` interface and integrated card into `page.tsx`.
- **Testing & Verification**:
  - Expanded feature test suite in `TextToSqlTest.php` with 6 dedicated test cases covering valid count, intent mismatches, ranking joins, monthly revenues, malformed verifier JSON, and connection timeouts.
  - Verified 100% pass rate: 25 feature tests (239 assertions).
  - Verified frontend build, lint, and TypeScript checks pass with 0 errors.

## [0.5.0] - 2026-09-03
### Changed (Phase 5)
- **Gemini Model Upgrade**:
  - Replaced obsolete `gemini-1.5-flash` model reference with currently supported stable model `gemini-3.5-flash`, resolving the API 404 `NOT_FOUND` error on `/v1beta/models`.
  - Upgraded model selection to be fully configurable through Laravel configuration (`config('services.gemini.model')`) and environment variables (`GEMINI_MODEL`), defaulting to `gemini-3.5-flash`.
  - Updated `backend/.env` and `backend/.env.example` with the `GEMINI_MODEL=gemini-3.5-flash` environment variable.
- **Testing**:
  - Added feature test asserting `gemini-3.5-flash` and custom configured model overrides are passed accurately into the Gemini API endpoint.
  - Verified backend test suite with 19 passing tests (187 assertions).
- **Verification**:
  - Verified full live pipeline end-to-end execution: AI (`gemini-3.5-flash`) → Guardrails → Schema Validation → MySQL → Results.

## [0.4.0] - 2026-08-29
### Added (Phase 4)
- **Security Hardening**:
  - String-literal stripping regex inside `SqlGuardrailService.php` to prevent false positive keyword blocks inside text.
  - Suppressed raw database exception messages in production within `SqlExecutorService.php`.
  - Dynamic CORS binding dynamically mapped using the `.env` parameter `ALLOWED_CORS_ORIGINS`.
- **API Resilience**:
  - Configured timeouts (`timeout(10)->connectTimeout(5)`) inside `GeminiAiService.php`.
  - Sanitized exceptions returned from the AI translation service to hide stack traces in production environments.
- **UX Polish**:
  - Bound result tables inside `max-h-96 overflow-y-auto` scrolling divisions.
  - Disabled chart generation in `ChartResult.tsx` when data sets yield $\le 1$ records.
- **Testing**:
  - Added test scenarios checking string-bound keywords, error suppressions, and connection timeouts.
  - Verified that all 18 feature test cases pass cleanly.
- **Documentation**:
  - Created Mermaid architectures, setup configurations, and setup commands in `README.md`.

## [0.3.0] - 2026-08-28
### Added (Phase 3)
- Interactive SQL Editor (`SqlEditor.tsx`) allowing editing, manual execution, and reset mappings.
- Visual Database Schema Explorer (`SchemaExplorer.tsx` and `TableSchema.tsx`) representing columns, keys, and foreign relationships.
- Endpoint `GET /api/v1/schema` serving database structures dynamically from `DatabaseSchemaService.php`.
- Re-execution pipeline for custom SQL executing through `SqlGuardrailService` and `SqlSchemaValidator` for safety.
- Advanced prompt few-shot context examples inside `GeminiAiService.php` prompt builder.
- Sidebar logs restoration mapping that loads SQL into the workspace statically without auto-running.
- Extended backend test suite coverage to 15 cases asserting custom queries, blocks, and schema endpoints.

## [0.2.0] - 2026-08-28
### Added (Phase 2)
- Real Gemini AI integration resolving requests using the generative API model `gemini-1.5-flash`.
- Centralized database schema service (`DatabaseSchemaService.php`) defining all fields and relationships.
- Database-aware validation service (`SqlSchemaValidator.php`) to dynamically verify referenced columns and tables.
- Interactive dashboard charting (`ChartResult.tsx`) using responsive, zero-dependency SVG curves and bars.
- Structured API payload return incorporating schema validation logs.
- Expanded backend feature test suite with 11 test cases covering guardrails, faked Gemini APIs, and validators.

## [0.1.0] - 2026-08-28
### Added
- Initial project architecture and structure planning.
- Approved implementation plan for Phase 1.
- Created project task tracker.
- Scaffolding backend and frontend directories.
