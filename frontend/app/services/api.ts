const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api/v1';

export interface QueryRequest {
  question: string;
}

export interface GuardrailsInfo {
  allowed: boolean;
  reason: string | null;
}

export interface ExecutionInfo {
  success: boolean;
  error: string | null;
  time_ms: number;
  results: Array<Record<string, unknown>>;
}

export interface SchemaValidationInfo {
  valid: boolean;
  reason: string | null;
}

export interface MultiplicationRiskInfo {
  detected: boolean;
  warning: string;
  details?: string;
  recommendation?: string;
}

export interface SemanticValidationInfo {
  valid: boolean;
  score: number;
  reason: string | null;
  interpretation: string;
  tables: string[];
  operations: string[];
  filters: string[];
  grouping: string[];
  ordering: string[];
  joins?: string[];
  grain?: string;
  aggregations?: string[];
  multiplication_risk?: MultiplicationRiskInfo | null;
}

export interface RelevantSchemaInfo {
  is_subset: boolean;
  matched_tables: string[];
  bridge_tables: string[];
  total_tables: number;
  included_tables: number;
}

export interface QueryResponse {
  question: string;
  sql: string | null;
  ambiguous?: boolean;
  clarification?: string | null;
  suggestions?: string[];
  relevant_schema?: RelevantSchemaInfo | null;
  guardrails: GuardrailsInfo;
  schema_validation?: SchemaValidationInfo | null;
  semantic_validation?: SemanticValidationInfo | null;
  execution: ExecutionInfo;
  confidence: number | null;
  explanation: string | null;
  visualization_type?: 'table' | 'bar' | 'line' | 'none' | null;
  saved_query_id?: number;
  saved_query_name?: string;
  target_database_name?: string | null;
}

export interface HistoryItem {
  id: number;
  question: string;
  generated_sql: string | null;
  passed_guardrails: boolean;
  execution_status: 'success' | 'failed' | 'blocked';
  execution_time_ms: number | null;
  error_message: string | null;
  confidence_score: number | null;
  source?: 'natural_language' | 'custom_sql' | 'saved_query';
  database_connection_id?: number | null;
  created_at: string;
  updated_at: string;
}

export interface HistoryResponse {
  success: boolean;
  data: HistoryItem[];
}

export interface ColumnInfo {
  name: string;
  type: string;
  primary?: boolean;
  foreign?: boolean;
  nullable?: boolean;
  referenced_table?: string | null;
  referenced_column?: string | null;
}

export interface TableInfo {
  name: string;
  columns: ColumnInfo[];
}

export interface RelationshipInfo {
  from: string;
  to: string;
  from_table?: string;
  from_column?: string;
  to_table?: string;
  to_column?: string;
  label: string;
}

export interface SchemaDetails {
  tables: TableInfo[];
  relationships: RelationshipInfo[];
}

export interface SchemaResponse {
  success: boolean;
  data: SchemaDetails;
}

export async function submitQuery(
  question: string,
  sql?: string,
  databaseConnectionId?: number | null,
  source?: 'natural_language' | 'custom_sql' | 'saved_query'
): Promise<QueryResponse> {
  const headers = getAuthHeaders();
  const bodyPayload: { question: string; sql?: string; database_connection_id?: number; source?: string } = { question };
  if (sql) {
    bodyPayload.sql = sql;
  }
  if (databaseConnectionId !== undefined && databaseConnectionId !== null) {
    bodyPayload.database_connection_id = databaseConnectionId;
  }
  if (source) {
    bodyPayload.source = source;
  }

  const res = await fetch(`${API_URL}/query`, {
    method: 'POST',
    headers,
    body: JSON.stringify(bodyPayload),
  });

  if (!res.ok) {
    const errorText = await res.text();
    let parsedError;
    try {
      parsedError = JSON.parse(errorText);
    } catch {
      // Ignored
    }
    throw new Error(parsedError?.message || `HTTP error! status: ${res.status}`);
  }

  return res.json();
}

export async function fetchHistory(): Promise<HistoryResponse> {
  const res = await fetch(`${API_URL}/history`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error(`HTTP error! status: ${res.status}`);
  }

  return res.json();
}

export async function fetchSchema(): Promise<SchemaResponse> {
  const res = await fetch(`${API_URL}/schema`, {
    method: 'GET',
    headers: {
      'Accept': 'application/json',
    },
    cache: 'no-store'
  });

  if (!res.ok) {
    throw new Error(`HTTP error! status: ${res.status}`);
  }

  return res.json();
}

/* =========================================================================
   Phase 1 Multi-Tenant SaaS: Authentication & Database Connections
   ========================================================================= */

export type UserRole = 'admin' | 'analyst' | 'viewer';
export type ResourceVisibility = 'private' | 'company';

export interface AuthCompany {
  id: number;
  name: string;
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  company_id?: number | null;
  company_name?: string | null;
  company: AuthCompany | null;
}

export interface CompanyMember {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  created_at: string;
  updated_at: string;
}

export interface CompanyMembersResponse {
  success: boolean;
  data: CompanyMember[];
}

export interface AddMemberParams {
  name: string;
  email: string;
  role: UserRole;
  password?: string;
}

export interface UpdateMemberRoleParams {
  role: UserRole;
}

export interface AuthResponse {
  success: boolean;
  message?: string;
  token?: string;
  user?: AuthUser;
}

export interface DatabaseConnectionItem {
  id: number;
  company_id: number;
  name: string;
  driver: 'mysql' | 'pgsql';
  host: string;
  port: number;
  database: string;
  username: string;
  status: 'connected' | 'failed' | 'untested';
  last_tested_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface DatabaseConnectionsResponse {
  success: boolean;
  data: DatabaseConnectionItem[];
}

export interface TestConnectionParams {
  driver: 'mysql' | 'pgsql';
  host: string;
  port: number;
  database: string;
  username: string;
  password?: string;
}

export interface SaveConnectionParams extends TestConnectionParams {
  name: string;
}

export function getStoredToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem('tts_auth_token');
}

export function setStoredToken(token: string | null): void {
  if (typeof window === 'undefined') return;
  if (token) {
    localStorage.setItem('tts_auth_token', token);
  } else {
    localStorage.removeItem('tts_auth_token');
  }
}

function getAuthHeaders(): HeadersInit {
  const token = getStoredToken();
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  return headers;
}

export async function registerTenant(data: {
  name: string;
  email: string;
  password: string;
  company_name: string;
}): Promise<AuthResponse> {
  const res = await fetch(`${API_URL}/auth/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(data),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Registration failed.');
  }

  if (body.token) {
    setStoredToken(body.token);
  }

  return body;
}

export async function loginTenant(data: {
  email: string;
  password: string;
}): Promise<AuthResponse> {
  const res = await fetch(`${API_URL}/auth/login`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(data),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Login failed.');
  }

  if (body.token) {
    setStoredToken(body.token);
  }

  return body;
}

export async function fetchCurrentUser(): Promise<AuthUser | null> {
  const token = getStoredToken();
  if (!token) return null;

  try {
    const res = await fetch(`${API_URL}/auth/me`, {
      method: 'GET',
      headers: getAuthHeaders(),
      cache: 'no-store',
    });

    if (!res.ok) {
      if (res.status === 401) {
        setStoredToken(null);
      }
      return null;
    }

    const body = await res.json();
    return body.user || null;
  } catch {
    return null;
  }
}

export async function logoutTenant(): Promise<void> {
  try {
    await fetch(`${API_URL}/auth/logout`, {
      method: 'POST',
      headers: getAuthHeaders(),
    });
  } finally {
    setStoredToken(null);
  }
}

export async function testDatabaseConnection(params: TestConnectionParams): Promise<{ success: boolean; message: string }> {
  const res = await fetch(`${API_URL}/database-connections/test`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  return {
    success: Boolean(body.success),
    message: body.message || (body.success ? 'Database connection successful.' : 'Connection test failed.'),
  };
}

export async function fetchDatabaseConnections(): Promise<DatabaseConnectionItem[]> {
  const res = await fetch(`${API_URL}/database-connections`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch connections: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data || [];
}

export async function saveDatabaseConnection(params: SaveConnectionParams): Promise<DatabaseConnectionItem> {
  const res = await fetch(`${API_URL}/database-connections`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Failed to save database connection.');
  }

  return body.data;
}

export async function deleteDatabaseConnection(id: number): Promise<void> {
  const res = await fetch(`${API_URL}/database-connections/${id}`, {
    method: 'DELETE',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'Failed to delete connection.');
  }
}

export async function fetchConnectionSchema(id: number): Promise<SchemaDetails> {
  const res = await fetch(`${API_URL}/database-connections/${id}/schema`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Failed to introspect database schema.');
  }

  return body.data;
}

export interface SavedQuery {
  id: number;
  company_id: number;
  user_id: number | null;
  database_connection_id: number | null;
  target_database_name: string | null;
  is_demo: boolean;
  name: string;
  description: string | null;
  natural_language_question: string;
  sql: string;
  dialect: string;
  result_visualization_type: 'table' | 'bar' | 'line' | 'none' | null;
  visibility: ResourceVisibility;
  is_owner?: boolean;
  can_edit?: boolean;
  created_at: string;
  updated_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  } | null;
  database_connection?: {
    id: number;
    name: string;
    driver: string;
    status?: string;
  } | null;
  dashboard_widgets_count?: number;
}

export interface CreateSavedQueryParams {
  name: string;
  description?: string | null;
  natural_language_question: string;
  sql: string;
  database_connection_id?: number | null;
  dialect?: 'mysql' | 'pgsql';
  result_visualization_type?: 'table' | 'bar' | 'line' | 'none' | null;
  visibility?: ResourceVisibility;
}

export interface UpdateSavedQueryParams {
  name?: string;
  description?: string | null;
  sql?: string;
  result_visualization_type?: 'table' | 'bar' | 'line' | 'none' | null;
  visibility?: ResourceVisibility;
}

export async function fetchSavedQueries(search?: string, databaseConnectionId?: number | null): Promise<SavedQuery[]> {
  const params = new URLSearchParams();
  if (search) params.append('search', search);
  if (databaseConnectionId !== undefined) {
    params.append('database_connection_id', databaseConnectionId === null ? 'null' : String(databaseConnectionId));
  }

  const queryStr = params.toString() ? `?${params.toString()}` : '';
  const res = await fetch(`${API_URL}/saved-queries${queryStr}`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch saved queries: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data || [];
}

export async function fetchSavedQuery(id: number): Promise<SavedQuery> {
  const res = await fetch(`${API_URL}/saved-queries/${id}`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch saved query: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data;
}

export async function createSavedQuery(params: CreateSavedQueryParams): Promise<SavedQuery> {
  const res = await fetch(`${API_URL}/saved-queries`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to save query.');
  }

  return body.data;
}

export async function updateSavedQuery(id: number, params: UpdateSavedQueryParams): Promise<SavedQuery> {
  const res = await fetch(`${API_URL}/saved-queries/${id}`, {
    method: 'PATCH',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to update saved query.');
  }

  return body.data;
}

export async function deleteSavedQuery(id: number): Promise<void> {
  const res = await fetch(`${API_URL}/saved-queries/${id}`, {
    method: 'DELETE',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'Failed to delete saved query.');
  }
}

export async function executeSavedQuery(id: number, sql?: string): Promise<QueryResponse> {
  const payload: { sql?: string } = {};
  if (sql) payload.sql = sql;

  const res = await fetch(`${API_URL}/saved-queries/${id}/execute`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(payload),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || `Failed to execute saved query: HTTP ${res.status}`);
  }

  return body;
}

export interface DashboardWidget {
  id: number;
  dashboard_id: number;
  saved_query_id: number;
  title: string | null;
  visualization_type: 'table' | 'bar' | 'line' | 'metric' | 'none' | null;
  position: number;
  width: number;
  height: number;
  created_at: string;
  updated_at: string;
  saved_query?: SavedQuery;
}

export interface Dashboard {
  id: number;
  company_id: number;
  user_id: number | null;
  name: string;
  description: string | null;
  visibility: ResourceVisibility;
  is_owner?: boolean;
  can_edit?: boolean;
  created_at: string;
  updated_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  } | null;
  widgets_count?: number;
  widgets?: DashboardWidget[];
}

export type DashboardDatePreset =
  | 'today'
  | 'yesterday'
  | 'last_7_days'
  | 'last_30_days'
  | 'this_month'
  | 'last_month'
  | 'this_quarter'
  | 'custom';

export interface DashboardFilterParams {
  date_preset?: DashboardDatePreset;
  date_from?: string;
  date_to?: string;
  bypass_cache?: boolean;
}

export interface WidgetExecutionResult {
  widget_id: number;
  saved_query_id: number | null;
  title: string;
  visualization_type: 'table' | 'bar' | 'line' | 'metric' | 'none' | null;
  position: number;
  width: number;
  height: number;
  target_database_name: string | null;
  success: boolean;
  error: string | null;
  time_ms: number;
  results: Array<Record<string, unknown>>;
  columns: string[];
  guardrails?: GuardrailsInfo | null;
  schema_validation?: SchemaValidationInfo | null;
  question?: string | null;
  sql?: string | null;
  filter_applied?: boolean;
  filter_status?: 'applied' | 'unsupported' | 'none';
  filter_message?: string | null;
  cache_hit?: boolean;
  interpretation?: SemanticValidationInfo | null;
}

export interface DashboardExecutionResponse {
  success: boolean;
  dashboard_id: number;
  dashboard_name: string;
  active_filters?: {
    date_preset?: DashboardDatePreset;
    date_from?: string | null;
    date_to?: string | null;
  };
  widgets: WidgetExecutionResult[];
}

export interface CreateDashboardParams {
  name: string;
  description?: string | null;
  visibility?: ResourceVisibility;
}

export interface UpdateDashboardParams {
  name?: string;
  description?: string | null;
  visibility?: ResourceVisibility;
}

export interface AddWidgetParams {
  saved_query_id: number;
  title?: string | null;
  visualization_type?: 'table' | 'bar' | 'line' | 'metric' | 'none' | null;
  position?: number;
  width?: number;
  height?: number;
}

export interface UpdateWidgetParams {
  title?: string | null;
  visualization_type?: 'table' | 'bar' | 'line' | 'metric' | 'none' | null;
  position?: number;
  width?: number;
  height?: number;
}

export async function fetchDashboards(search?: string): Promise<Dashboard[]> {
  const params = new URLSearchParams();
  if (search) params.append('search', search);

  const queryStr = params.toString() ? `?${params.toString()}` : '';
  const res = await fetch(`${API_URL}/dashboards${queryStr}`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch dashboards: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data || [];
}

export async function fetchDashboard(id: number): Promise<Dashboard> {
  const res = await fetch(`${API_URL}/dashboards/${id}`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch dashboard: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data;
}

export async function createDashboard(params: CreateDashboardParams): Promise<Dashboard> {
  const res = await fetch(`${API_URL}/dashboards`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to create dashboard.');
  }

  return body.data;
}

export async function updateDashboard(id: number, params: UpdateDashboardParams): Promise<Dashboard> {
  const res = await fetch(`${API_URL}/dashboards/${id}`, {
    method: 'PATCH',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to update dashboard.');
  }

  return body.data;
}

export async function deleteDashboard(id: number): Promise<void> {
  const res = await fetch(`${API_URL}/dashboards/${id}`, {
    method: 'DELETE',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'Failed to delete dashboard.');
  }
}

export async function addDashboardWidget(dashboardId: number, params: AddWidgetParams): Promise<DashboardWidget> {
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/widgets`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to add widget to dashboard.');
  }

  return body.data;
}

export async function updateDashboardWidget(dashboardId: number, widgetId: number, params: UpdateWidgetParams): Promise<DashboardWidget> {
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/widgets/${widgetId}`, {
    method: 'PATCH',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || 'Failed to update widget.');
  }

  return body.data;
}

export async function deleteDashboardWidget(dashboardId: number, widgetId: number): Promise<void> {
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/widgets/${widgetId}`, {
    method: 'DELETE',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'Failed to remove widget.');
  }
}

export async function executeDashboard(
  dashboardId: number,
  filters?: DashboardFilterParams
): Promise<DashboardExecutionResponse> {
  const payload = filters || {};
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/execute`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(payload),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || `Failed to execute dashboard: HTTP ${res.status}`);
  }

  return body;
}

export async function exportDashboardCsv(
  dashboardId: number,
  filters?: DashboardFilterParams
): Promise<Blob> {
  const params = new URLSearchParams();
  if (filters?.date_preset) params.append('date_preset', filters.date_preset);
  if (filters?.date_from) params.append('date_from', filters.date_from);
  if (filters?.date_to) params.append('date_to', filters.date_to);

  const queryStr = params.toString() ? `?${params.toString()}` : '';
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/export/csv${queryStr}`, {
    method: 'GET',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to export CSV: HTTP ${res.status}`);
  }

  return await res.blob();
}

export interface ExecutiveSummaryWidget {
  widget_id: number;
  title: string;
  visualization_type: string;
  success: boolean;
  filter_applied: boolean;
  filter_message?: string | null;
  kpi_value: unknown;
  row_count: number;
  results_preview: Array<Record<string, unknown>>;
  columns: string[];
  grain?: string | null;
  multiplication_risk?: MultiplicationRiskInfo | null;
  calculation_risk: boolean;
}

export interface ExecutiveSummaryResponse {
  success: boolean;
  dashboard: {
    id: number;
    name: string;
    description: string | null;
    company?: string | null;
    exported_at: string;
    active_filters: {
      date_preset?: string | null;
      date_from?: string | null;
      date_to?: string | null;
    };
  };
  summary_stats: {
    total_widgets: number;
    successful_widgets: number;
    failed_widgets: number;
  };
  widgets: ExecutiveSummaryWidget[];
}

export async function exportDashboardSummary(
  dashboardId: number,
  filters?: DashboardFilterParams
): Promise<ExecutiveSummaryResponse> {
  const params = new URLSearchParams();
  if (filters?.date_preset) params.append('date_preset', filters.date_preset);
  if (filters?.date_from) params.append('date_from', filters.date_from);
  if (filters?.date_to) params.append('date_to', filters.date_to);

  const queryStr = params.toString() ? `?${params.toString()}` : '';
  const res = await fetch(`${API_URL}/dashboards/${dashboardId}/export/summary${queryStr}`, {
    method: 'GET',
    headers: getAuthHeaders(),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || body.error || `Failed to fetch executive summary: HTTP ${res.status}`);
  }

  return body;
}

/* =========================================================================
   Phase 8: Team Collaboration, Roles & Resource Permissions
   ========================================================================= */

export async function fetchCompanyMembers(): Promise<CompanyMember[]> {
  const res = await fetch(`${API_URL}/company/members`, {
    method: 'GET',
    headers: getAuthHeaders(),
    cache: 'no-store',
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || `Failed to fetch team members: HTTP ${res.status}`);
  }

  const body = await res.json();
  return body.data || [];
}

export async function addCompanyMember(params: AddMemberParams): Promise<CompanyMember> {
  const res = await fetch(`${API_URL}/company/members`, {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Failed to add team member.');
  }

  return body.data;
}

export async function updateCompanyMemberRole(memberId: number, params: UpdateMemberRoleParams): Promise<CompanyMember> {
  const res = await fetch(`${API_URL}/company/members/${memberId}/role`, {
    method: 'PATCH',
    headers: getAuthHeaders(),
    body: JSON.stringify(params),
  });

  const body = await res.json();
  if (!res.ok) {
    throw new Error(body.message || 'Failed to update member role.');
  }

  return body.data;
}

export async function removeCompanyMember(memberId: number): Promise<void> {
  const res = await fetch(`${API_URL}/company/members/${memberId}`, {
    method: 'DELETE',
    headers: getAuthHeaders(),
  });

  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.message || 'Failed to remove team member.');
  }
}
