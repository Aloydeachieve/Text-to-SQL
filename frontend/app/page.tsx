'use client';

import React, { useState, useEffect } from 'react';
import { Header } from './components/dashboard/Header';
import { HistorySidebar } from './components/dashboard/HistorySidebar';
import { QueryInput } from './components/query/QueryInput';
import { Card } from './components/shared/Card';
import { Loader } from './components/shared/Loader';
import { SqlEditor } from './components/results/SqlEditor';
import { GuardrailCard } from './components/results/GuardrailCard';
import { ResultTable } from './components/results/ResultTable';
import { ConfidenceMeter } from './components/results/ConfidenceMeter';
import { ChartResult } from './components/results/ChartResult';
import { QueryIntentCard } from './components/results/QueryIntentCard';
import { SchemaExplorer } from './components/schema/SchemaExplorer';
import { DatabaseConnectionModal } from './components/dashboard/DatabaseConnectionModal';
import { SaveQueryModal } from './components/saved/SaveQueryModal';
import { EditSavedQueryModal } from './components/saved/EditSavedQueryModal';
import { SavedQueryList } from './components/saved/SavedQueryList';
import { DashboardList } from './components/dashboard/DashboardList';
import { DashboardDetail } from './components/dashboard/DashboardDetail';
import { CreateDashboardModal } from './components/dashboard/CreateDashboardModal';
import { AddWidgetModal } from './components/dashboard/AddWidgetModal';
import { TeamView } from './components/team/TeamView';
import {
  submitQuery,
  fetchHistory,
  fetchCurrentUser,
  fetchDatabaseConnections,
  fetchSavedQueries,
  deleteSavedQuery,
  updateSavedQuery,
  executeSavedQuery,
  fetchDashboards,
  fetchDashboard,
  createDashboard,
  updateDashboard,
  deleteDashboard,
  addDashboardWidget,
  QueryResponse,
  HistoryItem,
  AuthUser,
  DatabaseConnectionItem,
  SavedQuery,
  Dashboard as DashboardType,
  CreateDashboardParams,
  UpdateDashboardParams,
  AddWidgetParams,
  ResourceVisibility
} from './services/api';

export default function Dashboard() {
  const [activeView, setActiveView] = useState<'workspace' | 'saved_queries' | 'dashboards' | 'team'>('workspace');
  
  const [history, setHistory] = useState<HistoryItem[]>([]);
  const [isHistoryLoading, setIsHistoryLoading] = useState(false);
  
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null);
  const [isConnectionsModalOpen, setIsConnectionsModalOpen] = useState(false);
  const [databaseConnections, setDatabaseConnections] = useState<DatabaseConnectionItem[]>([]);
  const [selectedDatabaseId, setSelectedDatabaseId] = useState<number | null>(null);

  const [savedQueries, setSavedQueries] = useState<SavedQuery[]>([]);
  const [isSavedQueriesLoading, setIsSavedQueriesLoading] = useState(false);
  const [isSaveModalOpen, setIsSaveModalOpen] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [editingSavedQuery, setEditingSavedQuery] = useState<SavedQuery | null>(null);

  const [dashboards, setDashboards] = useState<DashboardType[]>([]);
  const [isDashboardsLoading, setIsDashboardsLoading] = useState(false);
  const [activeDashboard, setActiveDashboard] = useState<DashboardType | null>(null);
  const [isCreateDashboardModalOpen, setIsCreateDashboardModalOpen] = useState(false);
  const [editingDashboard, setEditingDashboard] = useState<DashboardType | null>(null);
  const [isAddWidgetModalOpen, setIsAddWidgetModalOpen] = useState(false);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeResult, setActiveResult] = useState<QueryResponse | null>(null);
  const [activeVisualizationType, setActiveVisualizationType] = useState<'table' | 'bar' | 'line' | 'none' | null>(null);
  const [selectedHistoryId, setSelectedHistoryId] = useState<number | undefined>(undefined);

  const loadConnections = async () => {
    try {
      const conns = await fetchDatabaseConnections();
      setDatabaseConnections(conns);
      if (selectedDatabaseId !== null && !conns.some(c => c.id === selectedDatabaseId)) {
        setSelectedDatabaseId(null);
      }
    } catch {
      setDatabaseConnections([]);
    }
  };

  const loadHistory = async (showLoading = false) => {
    if (showLoading) setIsHistoryLoading(true);
    try {
      const res = await fetchHistory();
      if (res.success) {
        setHistory(res.data);
      }
    } catch (err: unknown) {
      console.error('Failed to load query history:', err);
    } finally {
      setIsHistoryLoading(false);
    }
  };

  const loadSavedQueriesList = async () => {
    if (!currentUser) {
      setSavedQueries([]);
      return;
    }
    setIsSavedQueriesLoading(true);
    try {
      const data = await fetchSavedQueries();
      setSavedQueries(data);
    } catch (err: unknown) {
      console.error('Failed to load saved queries:', err);
    } finally {
      setIsSavedQueriesLoading(false);
    }
  };

  const loadDashboardsList = async () => {
    if (!currentUser) {
      setDashboards([]);
      return;
    }
    setIsDashboardsLoading(true);
    try {
      const data = await fetchDashboards();
      setDashboards(data);
    } catch (err: unknown) {
      console.error('Failed to load dashboards:', err);
    } finally {
      setIsDashboardsLoading(false);
    }
  };

  useEffect(() => {
    let ignore = false;
    fetchHistory()
      .then((res) => {
        if (!ignore && res.success) {
          setHistory(res.data);
        }
      })
      .catch((err) => console.error('Failed to load initial query history:', err));

    fetchCurrentUser()
      .then((user) => {
        if (!ignore) {
          setCurrentUser(user);
          if (user) {
            fetchDatabaseConnections()
              .then(conns => {
                if (!ignore) setDatabaseConnections(conns);
              })
              .catch(() => {});
            
            fetchSavedQueries()
              .then(queries => {
                if (!ignore) setSavedQueries(queries);
              })
              .catch(() => {});

            fetchDashboards()
              .then(dashs => {
                if (!ignore) setDashboards(dashs);
              })
              .catch(() => {});
          }
        }
      })
      .catch((err) => console.error('Failed to load current user:', err));

    return () => {
      ignore = true;
    };
  }, []);

  const handleUserChanged = (user: AuthUser | null) => {
    setCurrentUser(user);
    if (user) {
      loadConnections();
      loadSavedQueriesList();
      loadDashboardsList();
    } else {
      setDatabaseConnections([]);
      setSelectedDatabaseId(null);
      setSavedQueries([]);
      setDashboards([]);
      setActiveDashboard(null);
    }
  };

  const activeConnection = databaseConnections.find(c => c.id === selectedDatabaseId);
  const activeDatabaseName = activeConnection
    ? `${activeConnection.name} (${activeConnection.driver.toUpperCase()})`
    : 'Demo Database (MySQL)';

  const handleQuerySubmit = async (question: string) => {
    setIsLoading(true);
    setError(null);
    setActiveResult(null);
    setSelectedHistoryId(undefined);

    try {
      const result = await submitQuery(question, undefined, selectedDatabaseId, 'natural_language');
      setActiveResult(result);
      setActiveVisualizationType(result.visualization_type || 'bar');
      
      await loadHistory();
      if (history.length > 0) {
        setSelectedHistoryId(history[0].id);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'An error occurred while communicating with the server.';
      setError(msg);
      await loadHistory();
    } finally {
      setIsLoading(false);
    }
  };

  const handleSelectHistoryItem = (item: HistoryItem) => {
    setSelectedHistoryId(item.id);
    setError(null);
    
    setActiveResult({
      question: item.question,
      sql: item.generated_sql,
      guardrails: {
        allowed: item.passed_guardrails,
        reason: item.execution_status === 'blocked' ? item.error_message : null,
      },
      schema_validation: item.execution_status === 'failed' && item.error_message?.includes('Schema') ? {
        valid: false,
        reason: item.error_message,
      } : {
        valid: item.execution_status === 'success' || item.execution_status === 'failed',
        reason: null,
      },
      semantic_validation: null,
      execution: {
        success: item.execution_status === 'success',
        error: (item.execution_status === 'failed' || item.execution_status === 'blocked') ? item.error_message : null,
        time_ms: item.execution_time_ms || 0,
        results: [],
      },
      confidence: item.confidence_score,
      explanation: 'Restored from history log. Click "Execute Custom SQL" to run and fetch results.',
    });
  };

  const handleSqlSubmit = async (editedSql: string) => {
    if (!activeResult) return;
    setIsLoading(true);
    setError(null);

    try {
      const result = await submitQuery(activeResult.question, editedSql, selectedDatabaseId, 'custom_sql');
      setActiveResult(result);
      if (result.visualization_type) {
        setActiveVisualizationType(result.visualization_type);
      }
      await loadHistory();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'An error occurred while re-running custom SQL.';
      setError(msg);
      await loadHistory();
    } finally {
      setIsLoading(false);
    }
  };

  const handleOpenSavedQueryInWorkspace = (query: SavedQuery) => {
    setSelectedDatabaseId(query.database_connection_id);
    setActiveVisualizationType(query.result_visualization_type || 'bar');
    setSelectedHistoryId(undefined);
    setError(null);
    setActiveResult({
      question: query.natural_language_question,
      sql: query.sql,
      guardrails: { allowed: true, reason: null },
      schema_validation: { valid: true, reason: null },
      semantic_validation: null,
      execution: {
        success: false,
        error: null,
        time_ms: 0,
        results: [],
      },
      confidence: 1.0,
      explanation: `Loaded saved query: "${query.name}". Click "Execute Custom SQL" or edit the query above.`,
      visualization_type: query.result_visualization_type,
      saved_query_id: query.id,
      saved_query_name: query.name,
      target_database_name: query.target_database_name,
    });
    setActiveView('workspace');
  };

  const handleRunSavedQuery = async (query: SavedQuery) => {
    setActiveView('workspace');
    setIsLoading(true);
    setError(null);
    setActiveResult(null);
    setSelectedDatabaseId(query.database_connection_id);
    setActiveVisualizationType(query.result_visualization_type || 'bar');

    try {
      const result = await executeSavedQuery(query.id);
      setActiveResult(result);
      if (result.visualization_type) {
        setActiveVisualizationType(result.visualization_type);
      }
      await loadHistory();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'An error occurred while executing the saved query.';
      setError(msg);
    } finally {
      setIsLoading(false);
    }
  };

  const handleDeleteSavedQuery = async (queryId: number) => {
    await deleteSavedQuery(queryId);
    setSavedQueries((prev) => prev.filter((q) => q.id !== queryId));
  };

  const handleOpenDashboard = async (dashboard: DashboardType) => {
    try {
      const full = await fetchDashboard(dashboard.id);
      setActiveDashboard(full);
    } catch {
      setActiveDashboard(dashboard);
    }
  };

  const handleCreateOrUpdateDashboard = async (params: CreateDashboardParams | UpdateDashboardParams, id?: number) => {
    if (id) {
      const updated = await updateDashboard(id, params);
      setDashboards((prev) => prev.map((d) => (d.id === id ? updated : d)));
      if (activeDashboard?.id === id) {
        setActiveDashboard(updated);
      }
    } else {
      const created = await createDashboard(params as CreateDashboardParams);
      setDashboards((prev) => [created, ...prev]);
      setActiveDashboard(created);
    }
  };

  const handleDeleteDashboard = async (dashboardId: number) => {
    await deleteDashboard(dashboardId);
    setDashboards((prev) => prev.filter((d) => d.id !== dashboardId));
    if (activeDashboard?.id === dashboardId) {
      setActiveDashboard(null);
    }
  };

  const handleAddWidget = async (params: AddWidgetParams) => {
    if (!activeDashboard) return;
    const widget = await addDashboardWidget(activeDashboard.id, params);
    const updatedDashboard = {
      ...activeDashboard,
      widgets: [...(activeDashboard.widgets || []), widget],
      widgets_count: (activeDashboard.widgets_count || 0) + 1,
    };
    setActiveDashboard(updatedDashboard);
    setDashboards((prev) => prev.map((d) => (d.id === activeDashboard.id ? updatedDashboard : d)));
  };

  const handleUpdateQueryVisibility = async (queryId: number, visibility: ResourceVisibility) => {
    const updated = await updateSavedQuery(queryId, { visibility });
    setSavedQueries((prev) => prev.map((q) => (q.id === queryId ? updated : q)));
  };

  const handleUpdateDashboardVisibility = async (dashboardId: number, visibility: ResourceVisibility) => {
    const updated = await updateDashboard(dashboardId, { visibility });
    setDashboards((prev) => prev.map((d) => (d.id === dashboardId ? updated : d)));
    if (activeDashboard?.id === dashboardId) {
      setActiveDashboard(updated);
    }
  };

  const isAdmin = !currentUser || currentUser.role === 'admin';
  const isViewer = currentUser?.role === 'viewer';

  return (
    <div className="min-h-screen flex flex-col">
      <Header
        currentUser={currentUser}
        onOpenConnectionsModal={() => setIsConnectionsModalOpen(true)}
        activeView={activeView}
        onViewChange={(view) => {
          setActiveView(view);
          if (view !== 'dashboards') {
            setActiveDashboard(null);
          }
        }}
        savedQueriesCount={savedQueries.length}
        dashboardsCount={dashboards.length}
      />
      
      <div className="flex-1 flex overflow-hidden">
        <HistorySidebar
          history={history}
          isLoading={isHistoryLoading}
          onRefresh={() => loadHistory(true)}
          onSelectItem={handleSelectHistoryItem}
          selectedId={selectedHistoryId}
        />

        <main className="flex-1 overflow-y-auto p-6 space-y-6">
          {activeView === 'team' ? (
            <TeamView
              currentUser={currentUser}
              onOpenAuthModal={() => setIsConnectionsModalOpen(true)}
            />
          ) : activeView === 'dashboards' ? (
            activeDashboard ? (
              <DashboardDetail
                dashboard={activeDashboard}
                currentUser={currentUser}
                onBack={() => setActiveDashboard(null)}
                onEditDashboard={(d) => {
                  setEditingDashboard(d);
                  setIsCreateDashboardModalOpen(true);
                }}
                onOpenAddWidget={() => setIsAddWidgetModalOpen(true)}
                onViewQueryInWorkspace={(sq) => {
                  setActiveView('workspace');
                  handleOpenSavedQueryInWorkspace(sq);
                }}
                onDashboardUpdated={(updated) => {
                  setActiveDashboard(updated);
                  setDashboards((prev) => prev.map((d) => (d.id === updated.id ? updated : d)));
                }}
                onUpdateVisibility={handleUpdateDashboardVisibility}
              />
            ) : (
              <DashboardList
                dashboards={dashboards}
                isLoading={isDashboardsLoading}
                currentUser={currentUser}
                onOpenDashboard={handleOpenDashboard}
                onEditDashboard={(d) => {
                  setEditingDashboard(d);
                  setIsCreateDashboardModalOpen(true);
                }}
                onDeleteDashboard={handleDeleteDashboard}
                onCreateDashboard={() => {
                  setEditingDashboard(null);
                  setIsCreateDashboardModalOpen(true);
                }}
                onOpenAuthModal={() => setIsConnectionsModalOpen(true)}
                onUpdateVisibility={handleUpdateDashboardVisibility}
              />
            )
          ) : activeView === 'saved_queries' ? (
            <SavedQueryList
              savedQueries={savedQueries}
              databaseConnections={databaseConnections}
              isLoading={isSavedQueriesLoading}
              currentUser={currentUser}
              onOpenInWorkspace={handleOpenSavedQueryInWorkspace}
              onRunQuery={handleRunSavedQuery}
              onEditQuery={(q) => {
                setEditingSavedQuery(q);
                setIsEditModalOpen(true);
              }}
              onDeleteQuery={handleDeleteSavedQuery}
              onNavigateToWorkspace={() => setActiveView('workspace')}
              onOpenAuthModal={() => setIsConnectionsModalOpen(true)}
              onUpdateVisibility={handleUpdateQueryVisibility}
            />
          ) : (
            <>
              {/* Active Target Database Bar */}
              <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3.5 rounded-xl border border-slate-800/60 bg-slate-900/40 backdrop-blur-md">
                <div className="flex items-center space-x-3">
                  <div className="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400 shrink-0">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                    </svg>
                  </div>
                  <div>
                    <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                      Active Target Database
                    </div>
                    <div className="text-sm font-medium text-white flex items-center gap-2">
                      <span>{activeDatabaseName}</span>
                      {selectedDatabaseId === null ? (
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                          Default Demo
                        </span>
                      ) : (
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-blue-500/10 border border-blue-500/20 text-blue-400">
                          Tenant Isolated
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2 w-full sm:w-auto">
                  <select
                    aria-label="Select Target Database"
                    value={selectedDatabaseId ?? ''}
                    onChange={(e) => {
                      const val = e.target.value === '' ? null : Number(e.target.value);
                      setSelectedDatabaseId(val);
                      setActiveResult(null);
                      setError(null);
                    }}
                    className="flex-1 sm:w-64 px-3 py-1.5 text-xs bg-slate-800/80 border border-slate-700/60 rounded-lg text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                  >
                    <option value="">Demo Database (MySQL Seeded)</option>
                    {databaseConnections.map((conn) => (
                      <option key={conn.id} value={conn.id}>
                        {conn.name} ({conn.driver.toUpperCase()} &bull; {conn.database})
                      </option>
                    ))}
                  </select>

                  {currentUser ? (
                    isAdmin && (
                      <button
                        type="button"
                        onClick={() => setIsConnectionsModalOpen(true)}
                        className="px-3 py-1.5 text-xs font-medium bg-slate-800 hover:bg-slate-700 border border-slate-700/60 rounded-lg text-slate-300 hover:text-white transition-colors shrink-0"
                      >
                        + Manage
                      </button>
                    )
                  ) : (
                    <button
                      type="button"
                      onClick={() => setIsConnectionsModalOpen(true)}
                      className="px-3 py-1.5 text-xs font-medium bg-indigo-600/30 hover:bg-indigo-600/50 border border-indigo-500/40 rounded-lg text-indigo-200 hover:text-white transition-colors shrink-0"
                    >
                      Connect DB
                    </button>
                  )}
                </div>
              </div>

              {/* Query Input Section */}
              {isViewer ? (
                <Card title="Workspace Query Execution (Viewer Mode)" className="border-emerald-500/20 bg-emerald-950/10">
                  <div className="p-4 rounded-xl bg-slate-900/50 border border-emerald-500/20 text-xs text-slate-300 space-y-2">
                    <div className="flex items-center space-x-2 text-emerald-400 font-semibold">
                      <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span>Viewer Access: Read-Only Workspace</span>
                    </div>
                    <p className="text-slate-400 leading-relaxed">
                      You have a Viewer role in your organization. Arbitrary Natural Language and SQL queries are disabled to safeguard system resources. You can run pre-approved company queries under{' '}
                      <button onClick={() => setActiveView('saved_queries')} className="text-cyan-400 underline hover:text-cyan-300">
                        Saved Queries
                      </button>{' '}
                      or explore company{' '}
                      <button onClick={() => setActiveView('dashboards')} className="text-cyan-400 underline hover:text-cyan-300">
                        Dashboards
                      </button>.
                    </p>
                  </div>
                </Card>
              ) : (
                <Card title="Ask Your Database" className="glow-active">
                  <QueryInput onSubmit={handleQuerySubmit} isLoading={isLoading} />
                </Card>
              )}

              {/* Grid Layout: Results Workspace & Schema Explorer */}
              <div className="grid grid-cols-1 xl:grid-cols-4 gap-6 items-start">
                <div className="xl:col-span-3 space-y-6">
                  {/* Loading Indicator */}
                  {isLoading && (
                    <Card className="flex items-center justify-center py-12 bg-slate-900/10">
                      <Loader message="Processing query workspace transaction..." />
                    </Card>
                  )}

                  {/* Error Banner */}
                  {error && (
                    <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-sm flex items-start justify-between gap-3">
                      <div>
                        <span className="font-bold">Error:</span> {error}
                      </div>
                      <button
                        onClick={() => setError(null)}
                        className="text-rose-400 hover:text-rose-200 text-xs shrink-0"
                      >
                        Dismiss
                      </button>
                    </div>
                  )}

                  {/* Query Result Workspace */}
                  {activeResult && !isLoading && (
                    <div className="space-y-6 animate-fadeIn">
                      {/* Question Banner & Explanation */}
                      <Card className="bg-slate-900/10">
                        <div className="space-y-3">
                          <div className="flex items-center justify-between">
                            <div className="flex items-center space-x-2 text-xs text-slate-500">
                              <span className="font-mono">QUESTION</span>
                              {activeResult.saved_query_name && (
                                <span className="px-2 py-0.5 rounded-full text-[10px] bg-purple-500/10 border border-purple-500/20 text-purple-300 font-bold">
                                  Saved Query: {activeResult.saved_query_name}
                                </span>
                              )}
                            </div>

                            {activeResult.sql && !isViewer && (
                              <button
                                type="button"
                                onClick={() => {
                                  if (!currentUser) {
                                    setIsConnectionsModalOpen(true);
                                  } else {
                                    setIsSaveModalOpen(true);
                                  }
                                }}
                                className="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/40 text-blue-300 hover:text-white transition-all shadow-sm"
                              >
                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
                                </svg>
                                <span>{currentUser ? 'Save Query' : 'Sign in to Save'}</span>
                              </button>
                            )}
                          </div>

                          <h2 className="text-xl font-semibold text-white leading-snug">
                            &ldquo;{activeResult.question}&rdquo;
                          </h2>
                          {activeResult.explanation && (
                            <p className="text-sm text-slate-350 leading-relaxed pt-2 border-t border-slate-800/40">
                              {activeResult.explanation}
                            </p>
                          )}
                        </div>
                      </Card>

                      {/* Guardrails, Schema Validation, and Confidence Layout */}
                      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                        <GuardrailCard info={activeResult.guardrails} />
                        
                        {activeResult.schema_validation && (
                          <div className={`p-4 rounded-xl border flex items-start space-x-3 transition-all duration-200 ${
                            activeResult.schema_validation.valid
                              ? 'bg-blue-500/10 border-blue-500/20 text-blue-405'
                              : 'bg-rose-500/10 border-rose-500/20 text-rose-450'
                          }`}>
                            <div className="mt-0.5 shrink-0">
                              {activeResult.schema_validation.valid ? (
                                <svg className="w-5 h-5 text-blue-450" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                              ) : (
                                <svg className="w-5 h-5 text-rose-450" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                              )}
                            </div>
                            <div className="space-y-1">
                              <h4 className="text-xs font-bold uppercase tracking-wider">
                                {activeResult.schema_validation.valid ? 'Schema Verified' : 'Schema Failed'}
                              </h4>
                              <p className="text-xs text-slate-350 leading-relaxed">
                                {activeResult.schema_validation.valid
                                  ? 'All referenced database objects exist.'
                                  : activeResult.schema_validation.reason || 'Query references unknown columns or tables.'}
                              </p>
                            </div>
                          </div>
                        )}

                        {activeResult.guardrails.allowed && activeResult.confidence !== null && (
                          <ConfidenceMeter score={activeResult.confidence} />
                        )}
                      </div>

                      {/* Semantic Intent Verification, Ambiguity Clarification & Query Transparency Card */}
                      <QueryIntentCard
                        info={activeResult.semantic_validation}
                        question={activeResult.question}
                        isCustomSql={activeResult.explanation?.includes('custom') ?? false}
                        isAmbiguous={activeResult.ambiguous}
                        clarification={activeResult.clarification}
                        suggestions={activeResult.suggestions}
                        relevantSchema={activeResult.relevant_schema}
                        onSelectSuggestion={(suggestion) => handleQuerySubmit(suggestion)}
                      />

                      {/* SQL Code Workspace Editor */}
                      {activeResult.sql && (
                        <SqlEditor
                          originalSql={activeResult.sql}
                          onRun={handleSqlSubmit}
                          isLoading={isLoading}
                          readOnly={isViewer}
                        />
                      )}

                      {/* Dynamic Chart Visualizer with Visualization Type Toggle */}
                      {activeResult.guardrails.allowed && activeResult.schema_validation?.valid && activeResult.semantic_validation?.valid !== false && activeResult.execution.success && activeResult.execution.results.length > 0 && (
                        <ChartResult
                          results={activeResult.execution.results}
                          preferredType={activeVisualizationType || activeResult.visualization_type}
                          onTypeChange={(t) => setActiveVisualizationType(t)}
                        />
                      )}

                      {/* Data Table */}
                      {activeResult.guardrails.allowed && activeResult.schema_validation?.valid && activeResult.semantic_validation?.valid !== false && activeResult.execution.success && (
                        <Card title="Query Results">
                          <ResultTable execution={activeResult.execution} />
                        </Card>
                      )}
                    </div>
                  )}

                  {/* Empty Workspace State */}
                  {!activeResult && !isLoading && !error && (
                    <div className="flex flex-col items-center justify-center py-20 text-center space-y-4">
                      <div className="w-16 h-16 rounded-full bg-slate-900/40 border border-slate-800/50 flex items-center justify-center text-slate-500">
                        <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                        </svg>
                      </div>
                      <div className="space-y-1 max-w-sm">
                        <h3 className="text-sm font-semibold text-slate-300">No Query Executed</h3>
                        <p className="text-xs text-slate-550 leading-relaxed">
                          Type a natural language question in the box above or select one of the Quick Start prompts to query the connected database.
                        </p>
                      </div>
                    </div>
                  )}
                </div>

                {/* Sidebar Database Schema Explorer */}
                <div className="xl:col-span-1">
                  <SchemaExplorer
                    activeDatabaseConnectionId={selectedDatabaseId}
                    activeDatabaseName={activeDatabaseName}
                  />
                </div>
              </div>
            </>
          )}
        </main>
      </div>

      {/* Database Connection Modal */}
      <DatabaseConnectionModal
        isOpen={isConnectionsModalOpen}
        onClose={() => {
          setIsConnectionsModalOpen(false);
          if (currentUser) loadConnections();
        }}
        currentUser={currentUser}
        onUserChanged={handleUserChanged}
      />

      {/* Save Query Modal */}
      {activeResult && activeResult.sql && (
        <SaveQueryModal
          isOpen={isSaveModalOpen}
          onClose={() => setIsSaveModalOpen(false)}
          question={activeResult.question}
          sql={activeResult.sql}
          databaseConnectionId={selectedDatabaseId}
          targetDatabaseName={activeDatabaseName}
          defaultVisualizationType={activeVisualizationType || activeResult.visualization_type || 'bar'}
          onSaved={(savedQuery) => {
            setSavedQueries((prev) => [savedQuery, ...prev.filter((q) => q.id !== savedQuery.id)]);
            setActiveResult((prev) => prev ? {
              ...prev,
              saved_query_id: savedQuery.id,
              saved_query_name: savedQuery.name,
            } : null);
          }}
        />
      )}

      {/* Edit Saved Query Modal */}
      <EditSavedQueryModal
        isOpen={isEditModalOpen}
        onClose={() => {
          setIsEditModalOpen(false);
          setEditingSavedQuery(null);
        }}
        savedQuery={editingSavedQuery}
        onUpdated={(updatedQuery) => {
          setSavedQueries((prev) => prev.map((q) => (q.id === updatedQuery.id ? updatedQuery : q)));
          if (activeResult?.saved_query_id === updatedQuery.id) {
            setActiveResult((prev) => prev ? {
              ...prev,
              saved_query_name: updatedQuery.name,
              visualization_type: updatedQuery.result_visualization_type,
            } : null);
          }
        }}
      />

      {/* Create / Edit Dashboard Modal */}
      <CreateDashboardModal
        isOpen={isCreateDashboardModalOpen}
        onClose={() => {
          setIsCreateDashboardModalOpen(false);
          setEditingDashboard(null);
        }}
        dashboard={editingDashboard}
        onSubmit={handleCreateOrUpdateDashboard}
      />

      {/* Add Widget to Dashboard Modal */}
      {activeDashboard && (
        <AddWidgetModal
          isOpen={isAddWidgetModalOpen}
          onClose={() => setIsAddWidgetModalOpen(false)}
          savedQueries={savedQueries}
          onAddWidget={handleAddWidget}
          onNavigateToWorkspace={() => setActiveView('workspace')}
        />
      )}
    </div>
  );
}
