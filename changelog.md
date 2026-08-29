# Changelog

All notable changes to the "Text-to-SQL Interface with Guardrails" project will be documented in this file.

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
