'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  Dashboard,
  DashboardWidget,
  WidgetExecutionResult,
  executeDashboard,
  updateDashboardWidget,
  deleteDashboardWidget,
  SavedQuery,
  DashboardDatePreset,
  DashboardFilterParams,
  exportDashboardCsv,
  AuthUser,
  ResourceVisibility,
} from '../../services/api';
import { VisibilityBadge } from '../shared/VisibilityBadge';
import { ShareResourceModal } from '../shared/ShareResourceModal';

const PRESET_OPTIONS: { value: DashboardDatePreset | 'all_time'; label: string }[] = [
  { value: 'all_time', label: 'All Time (No Filter)' },
  { value: 'today', label: 'Today' },
  { value: 'yesterday', label: 'Yesterday' },
  { value: 'last_7_days', label: 'Last 7 Days' },
  { value: 'last_30_days', label: 'Last 30 Days' },
  { value: 'this_month', label: 'This Month' },
  { value: 'last_month', label: 'Last Month' },
  { value: 'this_quarter', label: 'This Quarter' },
  { value: 'custom', label: 'Custom Date Range...' },
];

interface DashboardDetailProps {
  dashboard: Dashboard;
  currentUser?: AuthUser | null;
  onBack: () => void;
  onEditDashboard: (dashboard: Dashboard) => void;
  onOpenAddWidget: () => void;
  onViewQueryInWorkspace: (savedQuery: SavedQuery) => void;
  onDashboardUpdated: (dashboard: Dashboard) => void;
  onUpdateVisibility?: (dashboardId: number, visibility: ResourceVisibility) => Promise<void>;
}

export function DashboardDetail({
  dashboard,
  currentUser,
  onBack,
  onEditDashboard,
  onOpenAddWidget,
  onViewQueryInWorkspace,
  onDashboardUpdated,
  onUpdateVisibility,
}: DashboardDetailProps) {
  const [widgetResults, setWidgetResults] = useState<Record<number, WidgetExecutionResult>>({});
  const [isExecuting, setIsExecuting] = useState(true);
  const [isRefreshingFresh, setIsRefreshingFresh] = useState(false);
  const [isExportingCsv, setIsExportingCsv] = useState(false);
  const [executionError, setExecutionError] = useState<string | null>(null);
  const [deletingWidgetId, setDeletingWidgetId] = useState<number | null>(null);
  const [isShareModalOpen, setIsShareModalOpen] = useState(false);

  const canEdit = Boolean(currentUser && (currentUser.role === 'admin' || dashboard.user_id === currentUser.id || dashboard.can_edit === true));
  const isViewer = currentUser?.role === 'viewer';

  // Filter State
  const [selectedPreset, setSelectedPreset] = useState<DashboardDatePreset | 'all_time'>('all_time');
  const [customFrom, setCustomFrom] = useState<string>('');
  const [customTo, setCustomTo] = useState<string>('');
  const [activeFilters, setActiveFilters] = useState<DashboardFilterParams | null>(null);

  // Modal State for Calculation Risk Details
  const [selectedRiskWidget, setSelectedRiskWidget] = useState<WidgetExecutionResult | null>(null);

  // Load and execute dashboard with caching and filter support
  const loadDashboardData = useCallback(async (filtersToApply?: DashboardFilterParams | null, bypassCache: boolean = false) => {
    setIsExecuting(true);
    if (bypassCache) setIsRefreshingFresh(true);
    setExecutionError(null);
    try {
      const payload: DashboardFilterParams = {
        ...(filtersToApply || {}),
        bypass_cache: bypassCache,
      };
      const res = await executeDashboard(dashboard.id, payload);
      const mapped: Record<number, WidgetExecutionResult> = {};
      for (const w of res.widgets) {
        mapped[w.widget_id] = w;
      }
      setWidgetResults(mapped);
    } catch (err: unknown) {
      setExecutionError(err instanceof Error ? err.message : 'Failed to execute dashboard.');
    } finally {
      setIsExecuting(false);
      setIsRefreshingFresh(false);
    }
  }, [dashboard.id]);

  useEffect(() => {
    let ignore = false;
    executeDashboard(dashboard.id)
      .then((res) => {
        if (!ignore) {
          const mapped: Record<number, WidgetExecutionResult> = {};
          for (const w of res.widgets) {
            mapped[w.widget_id] = w;
          }
          setWidgetResults(mapped);
          setIsExecuting(false);
        }
      })
      .catch((err: unknown) => {
        if (!ignore) {
          setExecutionError(err instanceof Error ? err.message : 'Failed to execute dashboard.');
          setIsExecuting(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [dashboard.id]);

  const handleApplyFilter = () => {
    if (selectedPreset === 'all_time') {
      setActiveFilters(null);
      loadDashboardData(null, false);
      return;
    }

    if (selectedPreset === 'custom') {
      if (!customFrom || !customTo) {
        alert('Please select both start and end dates.');
        return;
      }
      if (customFrom > customTo) {
        alert('Start date cannot be after end date.');
        return;
      }
      const f: DashboardFilterParams = {
        date_preset: 'custom',
        date_from: customFrom,
        date_to: customTo,
      };
      setActiveFilters(f);
      loadDashboardData(f, false);
      return;
    }

    const f: DashboardFilterParams = {
      date_preset: selectedPreset,
    };
    setActiveFilters(f);
    loadDashboardData(f, false);
  };

  const handleResetFilter = () => {
    setSelectedPreset('all_time');
    setCustomFrom('');
    setCustomTo('');
    setActiveFilters(null);
    loadDashboardData(null, false);
  };

  const handleRefreshCached = () => {
    loadDashboardData(activeFilters, false);
  };

  const handleForceFresh = () => {
    loadDashboardData(activeFilters, true);
  };

  const handleExportCsv = async () => {
    try {
      setIsExportingCsv(true);
      const blob = await exportDashboardCsv(dashboard.id, activeFilters || undefined);
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      const sanitizedName = dashboard.name.toLowerCase().replace(/[^a-z0-9]+/g, '-');
      a.download = `${sanitizedName}-export-${new Date().toISOString().split('T')[0]}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to export CSV.');
    } finally {
      setIsExportingCsv(false);
    }
  };

  const handlePrint = () => {
    window.print();
  };

  const handleDeleteWidget = async (widgetId: number) => {
    if (!window.confirm('Are you sure you want to remove this widget from the dashboard?')) {
      return;
    }
    setDeletingWidgetId(widgetId);
    try {
      await deleteDashboardWidget(dashboard.id, widgetId);
      const updatedWidgets = (dashboard.widgets || []).filter((w) => w.id !== widgetId);
      onDashboardUpdated({ ...dashboard, widgets: updatedWidgets });
      setWidgetResults((prev) => {
        const copy = { ...prev };
        delete copy[widgetId];
        return copy;
      });
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to delete widget.');
    } finally {
      setDeletingWidgetId(null);
    }
  };

  const handleUpdateVizType = async (widget: DashboardWidget, newType: 'metric' | 'bar' | 'line' | 'table') => {
    try {
      const updated = await updateDashboardWidget(dashboard.id, widget.id, {
        visualization_type: newType,
      });
      const updatedWidgets = (dashboard.widgets || []).map((w) => (w.id === widget.id ? updated : w));
      onDashboardUpdated({ ...dashboard, widgets: updatedWidgets });
      if (widgetResults[widget.id]) {
        setWidgetResults((prev) => ({
          ...prev,
          [widget.id]: {
            ...prev[widget.id],
            visualization_type: newType,
          },
        }));
      }
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to update visualization type.');
    }
  };

  const handleToggleWidth = async (widget: DashboardWidget) => {
    const newWidth = widget.width === 2 ? 1 : 2;
    try {
      const updated = await updateDashboardWidget(dashboard.id, widget.id, {
        width: newWidth,
      });
      const updatedWidgets = (dashboard.widgets || []).map((w) => (w.id === widget.id ? updated : w));
      onDashboardUpdated({ ...dashboard, widgets: updatedWidgets });
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to update widget width.');
    }
  };

  const widgets = dashboard.widgets || [];

  return (
    <div className="space-y-6 animate-fadeIn">
      {/* Top Navigation & Dashboard Header */}
      <div className="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 p-5 rounded-2xl border border-slate-800/80 bg-slate-900/40 backdrop-blur-md">
        <div className="space-y-1.5">
          <div className="flex items-center space-x-2">
            <button
              onClick={onBack}
              className="text-xs font-semibold text-blue-400 hover:text-blue-300 flex items-center space-x-1 transition-colors print:hidden"
            >
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
              </svg>
              <span>Back to Dashboards</span>
            </button>
            <span className="text-slate-600 print:hidden">&bull;</span>
            <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20">
              {widgets.length} {widgets.length === 1 ? 'Widget' : 'Widgets'}
            </span>
            <VisibilityBadge visibility={dashboard.visibility || 'private'} />
            <span className="text-slate-600 print:hidden">&bull;</span>
            <span className="text-xs text-slate-400">
              {dashboard.user_id === currentUser?.id
                ? 'Owner: You'
                : dashboard.user
                ? `Owner: ${dashboard.user.name}`
                : 'Company Resource'}
            </span>
          </div>

          <h2 className="text-2xl font-bold text-white tracking-tight leading-tight">
            {dashboard.name}
          </h2>

          {dashboard.description && (
            <p className="text-xs text-slate-400 max-w-2xl leading-relaxed">
              {dashboard.description}
            </p>
          )}
        </div>

        {/* Action Controls */}
        <div className="flex items-center space-x-2 flex-wrap gap-y-2 print:hidden">
          {/* Share Dashboard for Owner/Admin */}
          {canEdit && onUpdateVisibility && (
            <button
              onClick={() => setIsShareModalOpen(true)}
              title="Share or change visibility"
              className="px-3 py-1.5 text-xs font-semibold text-cyan-300 bg-cyan-500/10 hover:bg-cyan-500/20 border border-cyan-500/30 rounded-xl transition-all shadow-sm flex items-center space-x-1.5"
            >
              <svg className="w-3.5 h-3.5 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
              </svg>
              <span>Share</span>
            </button>
          )}

          {/* Refresh Cached */}
          <button
            onClick={handleRefreshCached}
            disabled={isExecuting}
            title="Refresh dashboard widgets (uses 5m memory cache for fast performance)"
            className="px-3 py-1.5 text-xs font-semibold text-slate-200 bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/80 rounded-xl transition-all shadow-sm flex items-center space-x-1.5 disabled:opacity-50"
          >
            <svg
              className={`w-3.5 h-3.5 text-blue-400 ${isExecuting && !isRefreshingFresh ? 'animate-spin' : ''}`}
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span>Refresh</span>
          </button>

          {/* Force Fresh Live DB Execution */}
          <button
            onClick={handleForceFresh}
            disabled={isExecuting}
            title="Force fresh execution against live database connection, bypassing all short-lived caches"
            className="px-3 py-1.5 text-xs font-semibold text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 rounded-xl transition-all shadow-sm flex items-center space-x-1.5 disabled:opacity-50"
          >
            <svg
              className={`w-3.5 h-3.5 text-amber-400 ${isRefreshingFresh ? 'animate-spin' : ''}`}
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span>Force Fresh</span>
          </button>

          {/* Export CSV */}
          <button
            onClick={handleExportCsv}
            disabled={isExportingCsv || isExecuting}
            title="Export dashboard widget data as RFC 4180 CSV with formula sanitization"
            className="px-3 py-1.5 text-xs font-semibold text-slate-200 bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/80 rounded-xl transition-all shadow-sm flex items-center space-x-1.5 disabled:opacity-50"
          >
            {isExportingCsv ? (
              <svg className="w-3.5 h-3.5 animate-spin text-emerald-400" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            ) : (
              <svg className="w-3.5 h-3.5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            )}
            <span>{isExportingCsv ? 'Exporting...' : 'Export CSV'}</span>
          </button>

          {/* Print / PDF View */}
          <button
            onClick={handlePrint}
            title="Open printable executive layout or save as PDF"
            className="px-3 py-1.5 text-xs font-semibold text-slate-200 bg-slate-800/80 hover:bg-slate-700/80 border border-slate-700/80 rounded-xl transition-all shadow-sm flex items-center space-x-1.5"
          >
            <svg className="w-3.5 h-3.5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            <span>Print / PDF</span>
          </button>

          {/* Add Widget for Owner/Admin */}
          {canEdit && (
            <button
              onClick={onOpenAddWidget}
              className="px-3 py-1.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-1.5"
            >
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              <span>Add Widget</span>
            </button>
          )}

          {/* Edit Details for Owner/Admin */}
          {canEdit && (
            <button
              onClick={() => onEditDashboard(dashboard)}
              title="Edit dashboard details"
              className="p-2 text-slate-400 hover:text-white bg-slate-800/50 hover:bg-slate-800 border border-slate-700/60 rounded-xl transition-colors"
            >
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
          )}
        </div>
      </div>

      {/* Global Dashboard Date Filter Bar */}
      <div className="p-4 rounded-2xl border border-slate-800/80 bg-slate-900/40 backdrop-blur-md flex flex-col md:flex-row items-start md:items-center justify-between gap-3 text-xs print:hidden">
        <div className="flex items-center space-x-2 flex-wrap gap-y-2">
          <div className="flex items-center space-x-1.5 text-slate-300 font-semibold pr-2 border-r border-slate-800">
            <svg className="w-4 h-4 text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>Date Filter</span>
          </div>

          {/* Presets Select Dropdown */}
          <select
            value={selectedPreset}
            onChange={(e) => setSelectedPreset(e.target.value as DashboardDatePreset | 'all_time')}
            className="px-3 py-1.5 rounded-xl bg-slate-950 border border-slate-700/80 text-white text-xs font-medium focus:outline-none focus:ring-1 focus:ring-blue-500 transition-colors"
          >
            {PRESET_OPTIONS.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>

          {/* Custom Date Inputs if custom range selected */}
          {selectedPreset === 'custom' && (
            <div className="flex items-center space-x-1.5 animate-fadeIn">
              <input
                type="date"
                value={customFrom}
                onChange={(e) => setCustomFrom(e.target.value)}
                placeholder="From"
                className="px-2.5 py-1.5 rounded-xl bg-slate-950 border border-slate-700/80 text-white text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 font-mono"
              />
              <span className="text-slate-500">to</span>
              <input
                type="date"
                value={customTo}
                onChange={(e) => setCustomTo(e.target.value)}
                placeholder="To"
                className="px-2.5 py-1.5 rounded-xl bg-slate-950 border border-slate-700/80 text-white text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 font-mono"
              />
            </div>
          )}

          {/* Apply Filter Button */}
          <button
            onClick={handleApplyFilter}
            disabled={isExecuting}
            className="px-3 py-1.5 rounded-xl font-semibold bg-blue-600 hover:bg-blue-500 text-white transition-all shadow-sm flex items-center space-x-1 disabled:opacity-50"
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
            </svg>
            <span>Apply</span>
          </button>

          {/* Reset Filter Button */}
          {activeFilters && (
            <button
              onClick={handleResetFilter}
              className="px-2.5 py-1.5 rounded-xl text-slate-400 hover:text-white hover:bg-slate-800 transition-colors"
            >
              Reset
            </button>
          )}
        </div>

        {/* Active Filter Summary Status */}
        <div className="flex items-center space-x-2 text-[11px] text-slate-400">
          {activeFilters ? (
            <span className="px-2.5 py-1 rounded-full bg-blue-500/10 text-blue-300 border border-blue-500/20 font-medium">
              Active Filter: {PRESET_OPTIONS.find((p) => p.value === activeFilters.date_preset)?.label || activeFilters.date_preset}
              {activeFilters.date_from && activeFilters.date_to ? ` (${activeFilters.date_from} – ${activeFilters.date_to})` : ''}
            </span>
          ) : (
            <span className="text-slate-500 italic">No date filters applied (showing all historical data)</span>
          )}
        </div>
      </div>

      {/* Global Error Banner if entire dashboard execution failed */}
      {executionError && (
        <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>Dashboard Execution Error: {executionError}</span>
          </div>
          <button
            onClick={() => setExecutionError(null)}
            className="text-rose-400 hover:text-rose-200 text-xs underline"
          >
            Dismiss
          </button>
        </div>
      )}

      {/* Empty State: Dashboard has no widgets yet */}
      {widgets.length === 0 && (
        <div className="flex flex-col items-center justify-center py-20 text-center space-y-4 rounded-2xl border border-dashed border-slate-800 bg-slate-950/20 p-8">
          <div className="w-16 h-16 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
            <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
          </div>
          <div className="space-y-1 max-w-sm">
            <h3 className="text-base font-bold text-white">No Widgets Yet</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Add a saved query to start building your live dashboard.
            </p>
          </div>
          <button
            onClick={onOpenAddWidget}
            className="px-5 py-2.5 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-2"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            <span>Add Saved Query</span>
          </button>
        </div>
      )}

      {/* Widgets Responsive Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        {widgets.map((widget) => {
          const result = widgetResults[widget.id];
          const isDeleting = deletingWidgetId === widget.id;
          const colSpan = widget.width === 2 ? 'lg:col-span-2' : 'lg:col-span-1';

          return (
            <div
              key={widget.id}
              className={`p-5 rounded-2xl border border-slate-800/80 bg-slate-900/40 backdrop-blur-md shadow-sm space-y-4 transition-all duration-200 ${colSpan}`}
            >
              {/* Widget Header Bar */}
              <div className="flex items-start justify-between gap-3 border-b border-slate-800/60 pb-3">
                <div className="space-y-1">
                  <div className="flex items-center space-x-2 flex-wrap gap-y-1">
                    <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 border border-slate-700/60">
                      {result?.target_database_name || widget.saved_query?.target_database_name || 'Demo Database'}
                    </span>

                    {result?.time_ms !== undefined && result.time_ms > 0 && (
                      <span className="text-[10px] text-slate-500 font-mono">
                        {result.time_ms.toFixed(1)}ms
                      </span>
                    )}

                    {/* Cache indicator */}
                    {result?.cache_hit && (
                      <span
                        className="text-[10px] px-2 py-0.5 rounded-full font-mono bg-indigo-500/10 text-indigo-400 border border-indigo-500/20"
                        title="Loaded from 5-minute memory cache"
                      >
                        ⚡ Cached
                      </span>
                    )}

                    {/* Filter Status Badge */}
                    {result?.filter_applied && (
                      <span
                        className="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center space-x-1"
                        title={result.filter_message || 'Global date filter applied'}
                      >
                        <span>✓ {result.filter_message || 'Filtered'}</span>
                      </span>
                    )}

                    {result?.filter_status === 'unsupported' && (
                      <span
                        className="text-[10px] px-2 py-0.5 rounded-full font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center space-x-1"
                        title={result.filter_message || 'Date filter unavailable for this widget'}
                      >
                        <span>⚠ Date filter unavailable</span>
                      </span>
                    )}

                    {/* Calculation Risk Warning Badge */}
                    {result?.interpretation?.multiplication_risk?.detected && (
                      <button
                        onClick={() => setSelectedRiskWidget(result)}
                        className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-rose-500/15 hover:bg-rose-500/25 text-rose-300 border border-rose-500/30 flex items-center space-x-1 transition-colors cursor-pointer"
                        title="Potential fan-out multiplication risk detected. Click to inspect query interpretation."
                      >
                        <span>⚠ Calculation risk detected</span>
                        <svg className="w-3 h-3 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                      </button>
                    )}
                  </div>

                  <h3 className="text-sm font-bold text-white tracking-tight">
                    {widget.title || widget.saved_query?.name || 'Untitled Widget'}
                  </h3>
                </div>

                {/* Widget Quick Controls */}
                <div className="flex items-center space-x-1 shrink-0">
                  {/* View Query action for non-viewers */}
                  {widget.saved_query && !isViewer && (
                    <button
                      onClick={() => onViewQueryInWorkspace(widget.saved_query!)}
                      title="View Query in Workspace"
                      className="px-2 py-1 text-[11px] font-semibold text-blue-400 hover:text-blue-300 hover:bg-blue-950/40 rounded-lg transition-colors flex items-center space-x-1 border border-transparent hover:border-blue-800/40"
                    >
                      <span>View Query</span>
                      <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                      </svg>
                    </button>
                  )}

                  {/* Width Toggle, Viz Type & Delete Widget only for Owner/Admin */}
                  {canEdit && (
                    <>
                      {/* Width Toggle */}
                      <button
                        onClick={() => handleToggleWidth(widget)}
                        title={widget.width === 2 ? 'Make Half Width' : 'Make Full Width'}
                        className="p-1 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
                      >
                        <span className="text-[10px] font-mono px-1 border border-current rounded">
                          {widget.width === 2 ? '½' : '1/1'}
                        </span>
                      </button>

                      {/* Visualization Type Selector */}
                      <div className="flex items-center bg-slate-950/80 p-0.5 rounded-lg border border-slate-800">
                        {(['metric', 'bar', 'line', 'table'] as const).map((type) => {
                          const isActive = (widget.visualization_type || 'bar') === type;
                          return (
                            <button
                              key={type}
                              onClick={() => handleUpdateVizType(widget, type)}
                              title={`Switch to ${type} view`}
                              className={`px-1.5 py-0.5 text-[10px] rounded capitalize transition-colors ${
                                isActive
                                  ? 'bg-blue-600 text-white font-bold'
                                  : 'text-slate-400 hover:text-white'
                              }`}
                            >
                              {type}
                            </button>
                          );
                        })}
                      </div>

                      {/* Delete Widget */}
                      <button
                        onClick={() => handleDeleteWidget(widget.id)}
                        disabled={isDeleting}
                        title="Remove Widget"
                        className="p-1 text-slate-400 hover:text-rose-400 rounded-lg hover:bg-rose-500/10 transition-colors"
                      >
                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </>
                  )}
                </div>
              </div>

              {/* Widget Body */}
              <div className="min-h-[160px] flex flex-col justify-center">
                {/* 1. Loading State */}
                {isExecuting && !result && (
                  <div className="py-12 flex flex-col items-center justify-center space-y-2 text-slate-400">
                    <svg className="w-5 h-5 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                    <span className="text-xs">Executing query...</span>
                  </div>
                )}

                {/* 2. Partial Failure State (Friendly, credentials-redacted error) */}
                {result && !result.success && (
                  <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs space-y-1.5 animate-fadeIn">
                    <div className="flex items-center space-x-2 font-bold">
                      <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                      </svg>
                      <span>Unable to load widget data</span>
                    </div>
                    <p className="text-[11px] text-rose-300/90 leading-relaxed pl-6">
                      {result.error || 'Database connection unavailable or schema altered.'}
                    </p>
                  </div>
                )}

                {/* 3. Successful Data Rendering */}
                {result && result.success && (
                  <>
                    {result.results.length === 0 ? (
                      <div className="py-8 text-center text-xs text-slate-500">
                        Query returned 0 rows.
                      </div>
                    ) : (
                      <WidgetDataView
                        type={widget.visualization_type || 'bar'}
                        results={result.results}
                        columns={result.columns}
                      />
                    )}
                  </>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Calculation Risk Details Modal */}
      {selectedRiskWidget && (
        <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-sm flex items-center justify-center p-4 print:hidden animate-fadeIn">
          <div className="bg-slate-900 border border-slate-700/80 rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-2xl text-slate-200">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div className="flex items-center space-x-2.5">
                <div className="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                  <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div>
                  <h3 className="text-sm font-bold text-white">Calculation Risk Analysis</h3>
                  <p className="text-[11px] text-slate-400">Query Grain & Multiplication Detection</p>
                </div>
              </div>
              <button
                onClick={() => setSelectedRiskWidget(null)}
                className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            {/* Warning Box */}
            <div className="p-4 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs space-y-2 text-amber-200">
              <div className="font-bold flex items-center space-x-1.5 text-amber-300">
                <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{selectedRiskWidget.interpretation?.multiplication_risk?.warning || 'Potential Fan-Out Multiplication Risk'}</span>
              </div>
              {selectedRiskWidget.interpretation?.multiplication_risk?.details && (
                <p className="text-[11px] text-amber-300/80 leading-relaxed pl-5.5">
                  {selectedRiskWidget.interpretation.multiplication_risk.details}
                </p>
              )}
              {selectedRiskWidget.interpretation?.multiplication_risk?.recommendation && (
                <div className="mt-2 pt-2 border-t border-amber-500/20 text-[11px] text-amber-300">
                  <span className="font-bold">Recommendation: </span>
                  {selectedRiskWidget.interpretation.multiplication_risk.recommendation}
                </div>
              )}
            </div>

            {/* Semantic Query Metadata */}
            <div className="space-y-3 text-xs">
              <div className="grid grid-cols-2 gap-2 text-[11px]">
                <div className="p-2.5 rounded-lg bg-slate-800/60 border border-slate-700/60 space-y-1">
                  <span className="text-slate-400 uppercase tracking-wider text-[9px] font-bold">Query Grain</span>
                  <div className="font-semibold text-white">
                    {selectedRiskWidget.interpretation?.grain || 'Single Row / Summary'}
                  </div>
                </div>
                <div className="p-2.5 rounded-lg bg-slate-800/60 border border-slate-700/60 space-y-1">
                  <span className="text-slate-400 uppercase tracking-wider text-[9px] font-bold">Tables Referenced</span>
                  <div className="font-semibold text-white truncate">
                    {selectedRiskWidget.interpretation?.tables?.join(', ') || 'N/A'}
                  </div>
                </div>
              </div>

              {selectedRiskWidget.interpretation?.joins && selectedRiskWidget.interpretation.joins.length > 0 && (
                <div className="p-2.5 rounded-lg bg-slate-800/60 border border-slate-700/60 space-y-1 text-[11px]">
                  <span className="text-slate-400 uppercase tracking-wider text-[9px] font-bold">JOIN Operations</span>
                  <div className="font-mono text-slate-300 text-[10px]">
                    {selectedRiskWidget.interpretation.joins.join(', ')}
                  </div>
                </div>
              )}

              {/* Underlying SQL preview */}
              <div className="space-y-1 text-[11px]">
                <span className="text-slate-400 font-medium">Executed Query</span>
                <pre className="p-3 rounded-lg bg-slate-950 border border-slate-800 font-mono text-[10px] text-slate-300 overflow-x-auto whitespace-pre-wrap max-h-32">
                  {selectedRiskWidget.sql}
                </pre>
              </div>
            </div>

            <div className="pt-2 flex justify-end">
              <button
                onClick={() => setSelectedRiskWidget(null)}
                className="px-4 py-2 text-xs font-semibold text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Share Modal */}
      {isShareModalOpen && onUpdateVisibility && (
        <ShareResourceModal
          isOpen={isShareModalOpen}
          resourceTitle={dashboard.name}
          resourceType="Dashboard"
          currentVisibility={dashboard.visibility || 'private'}
          onClose={() => setIsShareModalOpen(false)}
          onSave={async (vis) => {
            await onUpdateVisibility(dashboard.id, vis);
            onDashboardUpdated({ ...dashboard, visibility: vis });
          }}
        />
      )}
    </div>
  );
}

/**
 * Sub-component for rendering data according to visualization type:
 * - Metric / KPI
 * - Bar Chart (SVG)
 * - Line Chart (SVG)
 * - Table
 */
function WidgetDataView({
  type,
  results,
  columns,
}: {
  type: 'metric' | 'bar' | 'line' | 'table' | 'none' | null;
  results: Array<Record<string, unknown>>;
  columns: string[];
}) {
  // If Metric / KPI display
  if (type === 'metric' || (results.length === 1 && Object.keys(results[0]).length === 1)) {
    const firstRow = results[0];
    const key = Object.keys(firstRow)[0];
    const rawVal = firstRow[key];
    const numVal = typeof rawVal === 'number' ? rawVal : typeof rawVal === 'string' ? parseFloat(rawVal) || 0 : 0;

    const isCurrency =
      key.toLowerCase().includes('revenue') ||
      key.toLowerCase().includes('price') ||
      key.toLowerCase().includes('amount') ||
      key.toLowerCase().includes('sales');

    const formattedValue = isCurrency
      ? `$${numVal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
      : typeof rawVal === 'number'
      ? numVal.toLocaleString()
      : String(rawVal ?? '-');

    return (
      <div className="py-6 px-4 rounded-xl bg-gradient-to-br from-blue-950/30 via-slate-900/40 to-purple-950/20 border border-blue-500/10 flex flex-col items-center justify-center text-center space-y-1">
        <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400 font-mono">
          {key.replace(/_/g, ' ')}
        </div>
        <div className="text-3xl sm:text-4xl font-black text-white tracking-tight">
          {formattedValue}
        </div>
        <div className="text-[10px] text-slate-500">
          Live metric from current database state
        </div>
      </div>
    );
  }

  // If Bar or Line Chart
  if (type === 'bar' || type === 'line') {
    return <WidgetSvgChart results={results} type={type} />;
  }

  // Default: Tabular Data View
  return (
    <div className="overflow-x-auto max-h-64 rounded-xl border border-slate-800/80 bg-slate-950/40">
      <table className="w-full text-left text-xs border-collapse">
        <thead className="sticky top-0 bg-slate-900/90 border-b border-slate-800 text-slate-400 font-semibold text-[11px]">
          <tr>
            {columns.map((col) => (
              <th key={col} className="px-3 py-2 font-mono">
                {col}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-800/60 text-slate-300">
          {results.slice(0, 50).map((row, idx) => (
            <tr key={idx} className="hover:bg-slate-800/30 transition-colors">
              {columns.map((col) => {
                const val = row[col];
                return (
                  <td key={col} className="px-3 py-2 font-mono text-[11px] truncate max-w-[200px]">
                    {val === null || val === undefined ? (
                      <span className="text-slate-600">NULL</span>
                    ) : typeof val === 'object' ? (
                      JSON.stringify(val)
                    ) : (
                      String(val)
                    )}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Lightweight responsive SVG Chart for widgets (Bar and Line)
 */
function WidgetSvgChart({
  results,
  type,
}: {
  results: Array<Record<string, unknown>>;
  type: 'bar' | 'line';
}) {
  const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);

  if (!results || results.length === 0) return null;

  // Identify label and value keys
  const firstRow = results[0];
  const keys = Object.keys(firstRow);

  let labelKey = '';
  let valueKey = '';

  for (const key of keys) {
    const val = firstRow[key];
    if (typeof val === 'number' && key !== 'id' && !key.includes('_id')) {
      valueKey = key;
    } else if (typeof val === 'string' && !isNaN(Number(val)) && key !== 'id' && !key.includes('_id')) {
      valueKey = key;
    } else if (!labelKey) {
      labelKey = key;
    }
  }

  if (!labelKey) labelKey = keys[0];
  if (!valueKey) {
    const numKey = keys.find((k) => k !== labelKey && !k.toLowerCase().includes('id'));
    if (numKey) valueKey = numKey;
  }

  // Fallback to table if data cannot be charted
  if (!valueKey || labelKey === valueKey) {
    return (
      <div className="text-xs text-slate-500 py-6 text-center">
        Insufficient numeric dimensions for charting. Displaying raw data in table view.
      </div>
    );
  }

  const data = results.slice(0, 15).map((row) => {
    const rawVal = row[valueKey];
    const numVal = typeof rawVal === 'number' ? rawVal : typeof rawVal === 'string' ? parseFloat(rawVal) || 0 : 0;
    return {
      label: String(row[labelKey] ?? ''),
      value: numVal,
      displayValue:
        typeof rawVal === 'number' &&
        (valueKey.toLowerCase().includes('price') ||
          valueKey.toLowerCase().includes('amount') ||
          valueKey.toLowerCase().includes('revenue'))
          ? `$${numVal.toFixed(2)}`
          : numVal.toLocaleString(),
    };
  });

  const maxVal = Math.max(...data.map((d) => d.value), 1);
  const minVal = 0;

  const width = 500;
  const height = 180;
  const paddingLeft = 45;
  const paddingRight = 15;
  const paddingTop = 15;
  const paddingBottom = 35;

  const chartWidth = width - paddingLeft - paddingRight;
  const chartHeight = height - paddingTop - paddingBottom;

  const points = data.map((d, idx) => {
    const x = paddingLeft + idx * (chartWidth / (data.length > 1 ? data.length - 1 : 1));
    const y = paddingTop + chartHeight - (d.value / maxVal) * chartHeight;
    return { x, y, ...d };
  });

  const barGapRatio = 0.3;
  const totalBarWidth = chartWidth / data.length;
  const barWidth = totalBarWidth * (1 - barGapRatio);

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between text-[11px] text-slate-400 font-mono">
        <span>{valueKey.replace(/_/g, ' ')} by {labelKey.replace(/_/g, ' ')}</span>
        {hoveredIdx !== null && data[hoveredIdx] && (
          <span className="text-blue-400 font-bold">
            {data[hoveredIdx].label}: {data[hoveredIdx].displayValue}
          </span>
        )}
      </div>

      <div className="relative h-44 w-full flex items-center justify-center">
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-full overflow-visible">
          <defs>
            <linearGradient id="widgetBarGrad" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#3b82f6" stopOpacity="0.9" />
              <stop offset="100%" stopColor="#8b5cf6" stopOpacity="0.3" />
            </linearGradient>
            <linearGradient id="widgetAreaGrad" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#3b82f6" stopOpacity="0.4" />
              <stop offset="100%" stopColor="#3b82f6" stopOpacity="0.0" />
            </linearGradient>
          </defs>

          {/* Grid lines */}
          {[0, 0.5, 1].map((ratio, i) => {
            const y = paddingTop + chartHeight * ratio;
            const gridVal = maxVal - (maxVal - minVal) * ratio;
            return (
              <g key={i}>
                <line
                  x1={paddingLeft}
                  y1={y}
                  x2={width - paddingRight}
                  y2={y}
                  stroke="rgba(148, 163, 184, 0.1)"
                  strokeWidth="1"
                />
                <text
                  x={paddingLeft - 8}
                  y={y + 3}
                  fill="rgba(148, 163, 184, 0.5)"
                  fontSize="9"
                  textAnchor="end"
                  fontFamily="monospace"
                >
                  {gridVal >= 1000 ? `${(gridVal / 1000).toFixed(1)}k` : gridVal.toFixed(0)}
                </text>
              </g>
            );
          })}

          {/* Bar Chart Mode */}
          {type === 'bar' &&
            data.map((d, idx) => {
              const barHeight = (d.value / maxVal) * chartHeight;
              const x = paddingLeft + idx * totalBarWidth + (totalBarWidth * barGapRatio) / 2;
              const y = paddingTop + chartHeight - barHeight;
              const isHovered = hoveredIdx === idx;

              return (
                <g
                  key={idx}
                  onMouseEnter={() => setHoveredIdx(idx)}
                  onMouseLeave={() => setHoveredIdx(null)}
                  className="cursor-pointer"
                >
                  <rect
                    x={x}
                    y={y}
                    width={barWidth}
                    height={Math.max(barHeight, 2)}
                    rx={3}
                    fill={isHovered ? '#60a5fa' : 'url(#widgetBarGrad)'}
                    className="transition-all duration-200"
                  />
                  <text
                    x={x + barWidth / 2}
                    y={height - 10}
                    fill={isHovered ? '#ffffff' : 'rgba(148, 163, 184, 0.6)'}
                    fontSize="9"
                    textAnchor="middle"
                    className="truncate font-mono"
                  >
                    {d.label.length > 7 ? `${d.label.slice(0, 6)}…` : d.label}
                  </text>
                </g>
              );
            })}

          {/* Line Chart Mode */}
          {type === 'line' && (
            <>
              {/* Area */}
              <path
                d={`M ${points[0].x} ${points[0].y} ` +
                  points.slice(1).map((p) => `L ${p.x} ${p.y}`).join(' ') +
                  ` L ${points[points.length - 1].x} ${paddingTop + chartHeight} L ${points[0].x} ${paddingTop + chartHeight} Z`}
                fill="url(#widgetAreaGrad)"
              />

              {/* Line */}
              <path
                d={`M ${points[0].x} ${points[0].y} ` + points.slice(1).map((p) => `L ${p.x} ${p.y}`).join(' ')}
                fill="none"
                stroke="#3b82f6"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              />

              {/* Points */}
              {points.map((p, idx) => {
                const isHovered = hoveredIdx === idx;
                return (
                  <g
                    key={idx}
                    onMouseEnter={() => setHoveredIdx(idx)}
                    onMouseLeave={() => setHoveredIdx(null)}
                    className="cursor-pointer"
                  >
                    <circle
                      cx={p.x}
                      cy={p.y}
                      r={isHovered ? 5 : 3}
                      fill={isHovered ? '#60a5fa' : '#3b82f6'}
                      stroke="#0f172a"
                      strokeWidth="2"
                      className="transition-all duration-150"
                    />
                    <text
                      x={p.x}
                      y={height - 10}
                      fill={isHovered ? '#ffffff' : 'rgba(148, 163, 184, 0.6)'}
                      fontSize="9"
                      textAnchor="middle"
                      className="truncate font-mono"
                    >
                      {p.label.length > 7 ? `${p.label.slice(0, 6)}…` : p.label}
                    </text>
                  </g>
                );
              })}
            </>
          )}
        </svg>
      </div>
    </div>
  );
}
