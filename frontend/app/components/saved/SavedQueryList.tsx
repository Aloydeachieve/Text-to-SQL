'use client';

import React, { useState, useMemo } from 'react';
import { SavedQuery, DatabaseConnectionItem, AuthUser, ResourceVisibility } from '../../services/api';
import { VisibilityBadge } from '../shared/VisibilityBadge';
import { ShareResourceModal } from '../shared/ShareResourceModal';

interface SavedQueryListProps {
  savedQueries: SavedQuery[];
  databaseConnections: DatabaseConnectionItem[];
  isLoading: boolean;
  currentUser?: AuthUser | null;
  onOpenInWorkspace: (query: SavedQuery) => void;
  onRunQuery: (query: SavedQuery) => void;
  onEditQuery: (query: SavedQuery) => void;
  onDeleteQuery: (queryId: number) => Promise<void>;
  onNavigateToWorkspace: () => void;
  onOpenAuthModal?: () => void;
  onUpdateVisibility?: (queryId: number, visibility: ResourceVisibility) => Promise<void>;
}

export function SavedQueryList({
  savedQueries,
  databaseConnections,
  isLoading,
  currentUser,
  onOpenInWorkspace,
  onRunQuery,
  onEditQuery,
  onDeleteQuery,
  onNavigateToWorkspace,
  onOpenAuthModal,
  onUpdateVisibility,
}: SavedQueryListProps) {
  const [search, setSearch] = useState('');
  const [selectedConnectionFilter, setSelectedConnectionFilter] = useState<string>('all');
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [sharingQuery, setSharingQuery] = useState<SavedQuery | null>(null);

  // Client-side filtering
  const filteredQueries = useMemo(() => {
    return savedQueries.filter((q) => {
      // Connection filter
      if (selectedConnectionFilter === 'demo' && !q.is_demo && q.database_connection_id !== null) {
        return false;
      }
      if (
        selectedConnectionFilter !== 'all' &&
        selectedConnectionFilter !== 'demo' &&
        String(q.database_connection_id) !== selectedConnectionFilter
      ) {
        return false;
      }

      // Search text filter
      if (!search.trim()) return true;
      const term = search.toLowerCase();
      return (
        q.name.toLowerCase().includes(term) ||
        (q.description && q.description.toLowerCase().includes(term)) ||
        q.natural_language_question.toLowerCase().includes(term) ||
        q.sql.toLowerCase().includes(term)
      );
    });
  }, [savedQueries, search, selectedConnectionFilter]);

  const handleDelete = async (query: SavedQuery) => {
    if (!window.confirm(`Are you sure you want to delete "${query.name}"?`)) {
      return;
    }
    setDeletingId(query.id);
    try {
      await onDeleteQuery(query.id);
    } finally {
      setDeletingId(null);
    }
  };

  const getConnectionStatus = (q: SavedQuery) => {
    if (q.is_demo || q.database_connection_id === null) {
      if (q.is_demo) {
        return { label: 'Demo Database (MySQL)', badge: 'demo' };
      }
      return { label: q.target_database_name || 'Connection Unavailable', badge: 'unavailable' };
    }

    const conn = databaseConnections.find((c) => c.id === q.database_connection_id);
    if (!conn) {
      return { label: q.target_database_name || 'Connection Unavailable', badge: 'unavailable' };
    }

    return { label: `${conn.name} (${conn.driver.toUpperCase()})`, badge: 'connected' };
  };

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Demo Mode Prompt if not logged in */}
      {!currentUser && (
        <div className="p-4 rounded-2xl border border-blue-500/30 bg-blue-950/20 backdrop-blur-md flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-lg shadow-blue-500/5">
          <div className="flex items-center space-x-3">
            <span className="text-2xl">🏢</span>
            <div>
              <div className="text-xs font-bold text-white">Demo Mode Active</div>
              <div className="text-xs text-slate-400 leading-relaxed">
                Sign in or register your organization to save queries, customize visualization preferences, and reuse them across dashboards.
              </div>
            </div>
          </div>
          {onOpenAuthModal && (
            <button
              onClick={onOpenAuthModal}
              className="px-3.5 py-1.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-sm shadow-blue-500/20 shrink-0"
            >
              Sign In / Register
            </button>
          )}
        </div>
      )}

      {/* Search & Filter Header Bar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 rounded-xl border border-slate-800/60 bg-slate-900/40 backdrop-blur-md">
        <div className="flex-1 w-full sm:w-auto relative">
          <svg
            className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search queries by name, question, description..."
            className="w-full pl-10 pr-4 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          />
          {search && (
            <button
              onClick={() => setSearch('')}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white text-xs"
            >
              ✕
            </button>
          )}
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto shrink-0">
          <select
            aria-label="Filter by Database"
            value={selectedConnectionFilter}
            onChange={(e) => setSelectedConnectionFilter(e.target.value)}
            className="px-3 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-xl text-slate-200 focus:outline-none focus:ring-1 focus:ring-blue-500 cursor-pointer w-full sm:w-auto"
          >
            <option value="all">All Databases ({savedQueries.length})</option>
            <option value="demo">Demo Database</option>
            {databaseConnections.map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.name} ({c.driver.toUpperCase()})
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* Loading Skeleton */}
      {isLoading && savedQueries.length === 0 && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {[1, 2, 3, 4].map((i) => (
            <div key={i} className="p-5 rounded-2xl border border-slate-800/60 bg-slate-900/30 animate-pulse space-y-3">
              <div className="h-4 bg-slate-800 rounded w-1/2" />
              <div className="h-3 bg-slate-800/60 rounded w-3/4" />
              <div className="h-16 bg-slate-950/60 rounded-xl" />
            </div>
          ))}
        </div>
      )}

      {/* Empty State: Zero Saved Queries */}
      {!isLoading && savedQueries.length === 0 && (
        <div className="flex flex-col items-center justify-center py-20 text-center space-y-4 rounded-2xl border border-slate-800/40 bg-slate-950/20 p-8">
          <div className="w-16 h-16 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
            <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
            </svg>
          </div>
          <div className="space-y-1.5 max-w-md">
            <h3 className="text-base font-bold text-white">No Saved Queries Yet</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Save verified queries from your workspace to build an analytics library. Your team can re-run queries against connected databases with preserved visualization preferences.
            </p>
          </div>
          <button
            onClick={onNavigateToWorkspace}
            className="px-5 py-2.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-2"
          >
            <span>Ask a Question in Workspace</span>
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
          </button>
        </div>
      )}

      {/* Empty State: Search produced no matches */}
      {!isLoading && savedQueries.length > 0 && filteredQueries.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center space-y-3 rounded-2xl border border-slate-800/40 bg-slate-950/20 p-6">
          <p className="text-sm font-semibold text-slate-300">No matching saved queries</p>
          <p className="text-xs text-slate-500 max-w-xs">
            No queries match your current search query or database filter.
          </p>
          <button
            onClick={() => {
              setSearch('');
              setSelectedConnectionFilter('all');
            }}
            className="px-3.5 py-1.5 text-xs text-blue-400 hover:text-blue-300 font-medium transition-colors"
          >
            Clear Search & Filters
          </button>
        </div>
      )}

      {/* Saved Query Grid */}
      {!isLoading && filteredQueries.length > 0 && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {filteredQueries.map((query) => {
            const connStatus = getConnectionStatus(query);
            const isDeleting = deletingId === query.id;
            const isViewer = currentUser?.role === 'viewer';
            const canEdit = Boolean(
              currentUser &&
                (currentUser.role === 'admin' ||
                  query.user_id === currentUser.id ||
                  query.can_edit === true)
            );

            return (
              <div
                key={query.id}
                className="p-5 rounded-2xl border border-slate-800/80 bg-slate-900/40 hover:bg-slate-900/70 hover:border-slate-700/80 transition-all duration-200 flex flex-col justify-between space-y-4 shadow-sm"
              >
                <div className="space-y-3">
                  {/* Top Badges & Title */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        {/* Target DB Badge */}
                        {connStatus.badge === 'demo' ? (
                          <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            Demo DB
                          </span>
                        ) : connStatus.badge === 'connected' ? (
                          <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20 truncate max-w-[180px]">
                            {connStatus.label}
                          </span>
                        ) : (
                          <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                            Connection Unavailable
                          </span>
                        )}

                        {/* Visibility Badge */}
                        <VisibilityBadge visibility={query.visibility || 'private'} />

                        {/* Visualization Preference Badge */}
                        {query.result_visualization_type && query.result_visualization_type !== 'none' && (
                          <span className="text-[10px] px-2 py-0.5 rounded-full font-medium bg-purple-500/10 text-purple-300 border border-purple-500/20 capitalize">
                            {query.result_visualization_type} Chart
                          </span>
                        )}

                        {/* Used in Dashboards Badge */}
                        {query.dashboard_widgets_count !== undefined && query.dashboard_widgets_count > 0 && (
                          <span className="text-[10px] px-2 py-0.5 rounded-full font-medium bg-amber-500/10 text-amber-300 border border-amber-500/20">
                            📊 Used in {query.dashboard_widgets_count} {query.dashboard_widgets_count === 1 ? 'dashboard' : 'dashboards'}
                          </span>
                        )}

                        <span className="text-[10px] text-slate-500 font-mono">
                          {new Date(query.created_at).toLocaleDateString([], {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                          })}
                        </span>
                      </div>

                      <h4 className="text-base font-bold text-white tracking-tight leading-snug pt-0.5">
                        {query.name}
                      </h4>
                    </div>

                    {/* Quick Action Icons for Owner/Admin */}
                    {canEdit && (
                      <div className="flex items-center space-x-1 shrink-0">
                        {onUpdateVisibility && (
                          <button
                            type="button"
                            onClick={() => setSharingQuery(query)}
                            title="Share or change visibility"
                            className="p-1.5 text-slate-400 hover:text-cyan-400 rounded-lg hover:bg-slate-800 transition-colors"
                          >
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                            </svg>
                          </button>
                        )}

                        <button
                          type="button"
                          onClick={() => onEditQuery(query)}
                          title="Edit query details"
                          className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                        >
                          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                          </svg>
                        </button>

                        <button
                          type="button"
                          onClick={() => handleDelete(query)}
                          disabled={isDeleting}
                          title="Delete saved query"
                          className="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg hover:bg-slate-800 transition-colors disabled:opacity-50"
                        >
                          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        </button>
                      </div>
                    )}
                  </div>

                  {/* Description if present */}
                  {query.description && (
                    <p className="text-xs text-slate-400 leading-relaxed line-clamp-2">
                      {query.description}
                    </p>
                  )}

                  {/* Original Question Quote */}
                  <div className="p-3 rounded-xl bg-slate-950/40 border border-slate-850 text-xs text-slate-300 flex items-start space-x-2">
                    <span className="text-blue-400 font-bold shrink-0">&ldquo;</span>
                    <span className="italic line-clamp-2">{query.natural_language_question}</span>
                    <span className="text-blue-400 font-bold shrink-0">&rdquo;</span>
                  </div>

                  {/* SQL Snippet */}
                  <pre className="p-2.5 bg-slate-950/80 border border-slate-850 rounded-xl text-[11px] text-blue-300/90 font-mono overflow-x-auto max-h-20 whitespace-pre-wrap">
                    {query.sql}
                  </pre>
                </div>

                {/* Card Action Buttons */}
                <div className="flex items-center justify-between pt-3 border-t border-slate-800/60 text-xs">
                  <div className="text-[11px] text-slate-400 flex items-center space-x-1">
                    <span>
                      {query.user_id === currentUser?.id
                        ? 'Owner: You'
                        : query.user
                        ? `Owner: ${query.user.name}`
                        : 'Company Resource'}
                    </span>
                  </div>

                  <div className="flex items-center space-x-2">
                    {!isViewer && (
                      <button
                        onClick={() => onOpenInWorkspace(query)}
                        className="px-3 py-1.5 font-medium text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/50 rounded-lg transition-colors flex items-center space-x-1.5"
                      >
                        <svg className="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        <span>Open in Workspace</span>
                      </button>
                    )}

                    <button
                      onClick={() => onRunQuery(query)}
                      disabled={connStatus.badge === 'unavailable'}
                      title={connStatus.badge === 'unavailable' ? 'Database connection no longer available' : 'Re-run saved query'}
                      className="px-3.5 py-1.5 font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-40 disabled:cursor-not-allowed rounded-lg transition-all shadow-md shadow-blue-500/20 flex items-center space-x-1.5"
                    >
                      <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span>Run Query</span>
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Share Modal */}
      {sharingQuery && onUpdateVisibility && (
        <ShareResourceModal
          isOpen={Boolean(sharingQuery)}
          resourceTitle={sharingQuery.name}
          resourceType="Saved Query"
          currentVisibility={sharingQuery.visibility || 'private'}
          onClose={() => setSharingQuery(null)}
          onSave={async (vis) => {
            if (sharingQuery) {
              await onUpdateVisibility(sharingQuery.id, vis);
            }
          }}
        />
      )}
    </div>
  );
}
