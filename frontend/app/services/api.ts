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
  results: Array<Record<string, any>>;
}

export interface SchemaValidationInfo {
  valid: boolean;
  reason: string | null;
}

export interface QueryResponse {
  question: string;
  sql: string | null;
  guardrails: GuardrailsInfo;
  schema_validation?: SchemaValidationInfo | null;
  execution: ExecutionInfo;
  confidence: number | null;
  explanation: string | null;
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
}

export interface TableInfo {
  name: string;
  columns: ColumnInfo[];
}

export interface RelationshipInfo {
  from: string;
  to: string;
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

export async function submitQuery(question: string, sql?: string): Promise<QueryResponse> {
  const res = await fetch(`${API_URL}/query`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ question, sql }),
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
