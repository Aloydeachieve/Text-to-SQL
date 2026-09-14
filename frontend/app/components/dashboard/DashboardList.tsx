'use client';

import React, { useState, useMemo } from 'react';
import { Dashboard, AuthUser, ResourceVisibility } from '../../services/api';
import { VisibilityBadge } from '../shared/VisibilityBadge';
import { ShareResourceModal } from '../shared/ShareResourceModal';

interface DashboardListProps {
  dashboards: Dashboard[];
  isLoading: boolean;
  currentUser?: AuthUser | null;
  onOpenDashboard: (dashboard: Dashboard) => void;
  onEditDashboard: (dashboard: Dashboard) => void;
  onDeleteDashboard: (dashboardId: number) => Promise<void>;
  onCreateDashboard: () => void;
  onOpenAuthModal?: () => void;
  onUpdateVisibility?: (dashboardId: number, visibility: ResourceVisibility) => Promise<void>;
}

export function DashboardList({
  dashboards,
  isLoading,
  currentUser,
  onOpenDashboard,
  onEditDashboard,
  onDeleteDashboard,
  onCreateDashboard,
  onOpenAuthModal,
  onUpdateVisibility,
}: DashboardListProps) {
  const [search, setSearch] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [sharingDashboard, setSharingDashboard] = useState<Dashboard | null>(null);

  const isViewer = currentUser?.role === 'viewer';

  // Client-side instant filter
  const filteredDashboards = useMemo(() => {
    if (!search.trim()) return dashboards;
    const term = search.toLowerCase();
    return dashboards.filter(
      (d) =>
        d.name.toLowerCase().includes(term) ||
        (d.description && d.description.toLowerCase().includes(term))
    );
  }, [dashboards, search]);

  const handleDelete = async (dashboard: Dashboard) => {
    if (!window.confirm(`Are you sure you want to delete dashboard "${dashboard.name}"? This will also remove its widgets.`)) {
      return;
    }
    setDeletingId(dashboard.id);
    try {
      await onDeleteDashboard(dashboard.id);
    } finally {
      setDeletingId(null);
    }
  };

  const handleCreateClick = () => {
    if (!currentUser && onOpenAuthModal) {
      onOpenAuthModal();
      return;
    }
    onCreateDashboard();
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
                Sign in or register your organization to create custom dashboards, configure live widgets, and retain your business analytics.
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

      {/* Search & Action Bar */}
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
            placeholder="Search dashboards by name or description..."
            className="w-full pl-10 pr-4 py-2 text-xs bg-slate-950/60 border border-slate-800 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-all"
          />
        </div>

        {!isViewer && (
          <button
            onClick={handleCreateClick}
            className="px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-2 shrink-0"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            <span>New Dashboard</span>
          </button>
        )}
      </div>

      {/* Loading Skeleton */}
      {isLoading && dashboards.length === 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {[1, 2, 3].map((i) => (
            <div key={i} className="p-5 rounded-2xl border border-slate-800/60 bg-slate-900/30 animate-pulse space-y-3">
              <div className="h-4 bg-slate-800 rounded w-1/2" />
              <div className="h-3 bg-slate-800/60 rounded w-3/4" />
              <div className="h-20 bg-slate-950/60 rounded-xl" />
            </div>
          ))}
        </div>
      )}

      {/* Empty State: Zero Dashboards */}
      {!isLoading && dashboards.length === 0 && (
        <div className="flex flex-col items-center justify-center py-20 text-center space-y-4 rounded-2xl border border-slate-800/40 bg-slate-950/20 p-8">
          <div className="w-16 h-16 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
            <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
            </svg>
          </div>
          <div className="space-y-1.5 max-w-md">
            <h3 className="text-base font-bold text-white">No Dashboards Yet</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Combine saved queries into real-time business dashboards with metric cards, charts, and automatic tenant isolation.
            </p>
          </div>
          {!isViewer && (
            <button
              onClick={handleCreateClick}
              className="px-5 py-2.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-2"
            >
              <span>Create First Dashboard</span>
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
              </svg>
            </button>
          )}
        </div>
      )}

      {/* Empty State: Search produced no matches */}
      {!isLoading && dashboards.length > 0 && filteredDashboards.length === 0 && (
        <div className="flex flex-col items-center justify-center py-16 text-center space-y-3 rounded-2xl border border-slate-800/40 bg-slate-950/20 p-6">
          <p className="text-sm font-semibold text-slate-300">No matching dashboards</p>
          <p className="text-xs text-slate-500 max-w-xs">
            No dashboards match your current search query.
          </p>
          <button
            onClick={() => setSearch('')}
            className="px-3.5 py-1.5 text-xs text-blue-400 hover:text-blue-300 font-medium transition-colors"
          >
            Clear Search
          </button>
        </div>
      )}

      {/* Dashboard Cards Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        {filteredDashboards.map((dashboard) => {
          const isDeleting = deletingId === dashboard.id;
          const widgetsCount = dashboard.widgets_count ?? dashboard.widgets?.length ?? 0;
          const canEdit = Boolean(currentUser && (currentUser.role === 'admin' || dashboard.user_id === currentUser.id || dashboard.can_edit === true));

          return (
            <div
              key={dashboard.id}
              className="p-5 rounded-2xl border border-slate-800/80 bg-slate-900/40 hover:bg-slate-900/70 hover:border-slate-700/80 transition-all duration-200 flex flex-col justify-between space-y-5 shadow-sm group"
            >
              <div className="space-y-3">
                {/* Header & Badges */}
                <div className="flex items-start justify-between gap-3">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                        {widgetsCount} {widgetsCount === 1 ? 'Widget' : 'Widgets'}
                      </span>
                      <VisibilityBadge visibility={dashboard.visibility || 'private'} />
                      <span className="text-[10px] text-slate-500 font-mono">
                        {new Date(dashboard.created_at).toLocaleDateString([], {
                          month: 'short',
                          day: 'numeric',
                          year: 'numeric',
                        })}
                      </span>
                    </div>

                    <h4 className="text-base font-bold text-white tracking-tight leading-snug pt-1 group-hover:text-blue-300 transition-colors">
                      {dashboard.name}
                    </h4>
                  </div>

                  {/* Actions Dropdown / Buttons for Owner/Admin */}
                  {canEdit && (
                    <div className="flex items-center space-x-1 shrink-0">
                      {onUpdateVisibility && (
                        <button
                          type="button"
                          onClick={() => setSharingDashboard(dashboard)}
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
                        onClick={() => onEditDashboard(dashboard)}
                        title="Edit Dashboard"
                        className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                      >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                      </button>

                      <button
                        type="button"
                        onClick={() => handleDelete(dashboard)}
                        disabled={isDeleting}
                        title="Delete Dashboard"
                        className="p-1.5 text-slate-400 hover:text-rose-400 rounded-lg hover:bg-rose-500/10 transition-colors disabled:opacity-50"
                      >
                        {isDeleting ? (
                          <svg className="w-4 h-4 animate-spin text-rose-400" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                          </svg>
                        ) : (
                          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                          </svg>
                        )}
                      </button>
                    </div>
                  )}
                </div>

                {dashboard.description && (
                  <p className="text-xs text-slate-400 leading-relaxed line-clamp-2">
                    {dashboard.description}
                  </p>
                )}
              </div>

              {/* Bottom Card Action & Owner */}
              <div className="pt-3 border-t border-slate-800/80 flex items-center justify-between">
                <span className="text-[11px] text-slate-400">
                  {dashboard.user_id === currentUser?.id
                    ? 'Owner: You'
                    : dashboard.user
                    ? `Owner: ${dashboard.user.name}`
                    : 'Company Resource'}
                </span>

                <button
                  onClick={() => onOpenDashboard(dashboard)}
                  className="px-3.5 py-1.5 text-xs font-semibold text-blue-300 hover:text-white bg-blue-600/10 hover:bg-blue-600/30 border border-blue-500/20 hover:border-blue-500/40 rounded-lg transition-all flex items-center space-x-1.5"
                >
                  <span>Open</span>
                  <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
                  </svg>
                </button>
              </div>
            </div>
          );
        })}
      </div>

      {/* Share Modal */}
      {sharingDashboard && onUpdateVisibility && (
        <ShareResourceModal
          isOpen={Boolean(sharingDashboard)}
          resourceTitle={sharingDashboard.name}
          resourceType="Dashboard"
          currentVisibility={sharingDashboard.visibility || 'private'}
          onClose={() => setSharingDashboard(null)}
          onSave={async (vis) => {
            if (sharingDashboard) {
              await onUpdateVisibility(sharingDashboard.id, vis);
            }
          }}
        />
      )}
    </div>
  );
}
