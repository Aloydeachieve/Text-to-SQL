'use client';

import React, { useState } from 'react';
import { SemanticMetric } from '../../services/api';

interface MetricListProps {
  metrics: SemanticMetric[];
  isAdmin: boolean;
  isLoading: boolean;
  search: string;
  onSearchChange: (val: string) => void;
  activeOnly: boolean;
  onActiveOnlyToggle: () => void;
  onNewMetric: () => void;
  onEditMetric: (metric: SemanticMetric) => void;
  onDeleteMetric: (id: number) => void;
  onSelectMetricForLineage?: (id: number) => void;
}

export function MetricList({
  metrics,
  isAdmin,
  isLoading,
  search,
  onSearchChange,
  activeOnly,
  onActiveOnlyToggle,
  onNewMetric,
  onEditMetric,
  onDeleteMetric,
  onSelectMetricForLineage,
}: MetricListProps) {
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const handleDelete = async (metric: SemanticMetric) => {
    if (!confirm(`Are you sure you want to delete metric "${metric.name}"?`)) return;
    setDeletingId(metric.id);
    try {
      await onDeleteMetric(metric.id);
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="space-y-4">
      {/* Search & Actions Bar */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div className="flex items-center space-x-3 flex-1">
          <div className="relative flex-1 max-w-md">
            <input
              type="text"
              value={search}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder="Search metrics, sources, or columns..."
              className="w-full px-3.5 py-2 pl-9 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-emerald-500 transition-colors"
            />
            <span className="absolute left-3 top-2.5 text-slate-500 text-xs">🔍</span>
          </div>

          <button
            onClick={onActiveOnlyToggle}
            className={`px-3 py-2 rounded-xl text-xs font-semibold border transition-all ${
              activeOnly
                ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40'
                : 'bg-slate-900 border-slate-800 text-slate-400 hover:text-slate-200'
            }`}
          >
            {activeOnly ? 'Active Only ✓' : 'All Statuses'}
          </button>
        </div>

        {isAdmin && (
          <button
            onClick={onNewMetric}
            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-semibold flex items-center justify-center space-x-1.5 transition-all shadow-lg shadow-emerald-600/20 shrink-0"
          >
            <span>+ Define Business Metric</span>
          </button>
        )}
      </div>

      {/* Loading state */}
      {isLoading && (
        <div className="p-8 text-center text-slate-500 text-xs">
          Loading business metrics...
        </div>
      )}

      {/* Empty state */}
      {!isLoading && metrics.length === 0 && (
        <div className="p-8 text-center rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 space-y-2">
          <span className="text-2xl">📊</span>
          <h3 className="text-sm font-semibold text-slate-300">No Business Metrics Found</h3>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            Define canonical metrics like Revenue, Active Users, or Orders to ensure Text-to-SQL targets the correct source tables and columns.
          </p>
          {isAdmin && (
            <button
              onClick={onNewMetric}
              className="mt-2 px-3 py-1.5 bg-emerald-600/30 hover:bg-emerald-600/50 text-emerald-300 border border-emerald-500/30 rounded-lg text-xs font-medium transition-all"
            >
              + Define First Metric
            </button>
          )}
        </div>
      )}

      {/* Metrics Grid */}
      {!isLoading && metrics.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {metrics.map((metric) => (
            <div
              key={metric.id}
              className="p-4 rounded-2xl border border-slate-800/80 bg-slate-900/50 hover:border-slate-700/80 transition-all space-y-3 flex flex-col justify-between group shadow-sm"
            >
              <div className="space-y-2">
                {/* Header: Name + Badges */}
                <div className="flex items-start justify-between gap-2">
                  <div className="space-y-0.5">
                    <div className="flex items-center space-x-2 flex-wrap gap-1">
                      <h4 className="text-sm font-bold text-slate-100">{metric.name}</h4>
                      {metric.is_source_of_truth && (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                          ⭐ Source of Truth
                        </span>
                      )}
                      {!metric.is_active && (
                        <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-800 text-slate-400">
                          Inactive
                        </span>
                      )}
                    </div>
                    <span className="text-[11px] font-mono text-slate-500 block">
                      slug: {metric.slug}
                    </span>
                  </div>

                  {/* Admin Action Menu */}
                  {isAdmin && (
                    <div className="flex items-center space-x-1 shrink-0 opacity-80 group-hover:opacity-100 transition-opacity">
                      <button
                        onClick={() => onEditMetric(metric)}
                        className="p-1 text-slate-400 hover:text-emerald-400 rounded hover:bg-slate-800 transition-colors text-xs"
                        title="Edit Metric"
                      >
                        ✏️
                      </button>
                      <button
                        onClick={() => handleDelete(metric)}
                        disabled={deletingId === metric.id}
                        className="p-1 text-slate-400 hover:text-rose-400 rounded hover:bg-slate-800 transition-colors text-xs disabled:opacity-50"
                        title="Delete Metric"
                      >
                        🗑️
                      </button>
                    </div>
                  )}
                </div>

                {/* Definition */}
                <p className="text-xs text-slate-300 leading-relaxed">
                  {metric.definition}
                </p>

                {/* Calculation Specs Card */}
                <div className="bg-slate-950/60 p-3 rounded-xl border border-slate-800/80 space-y-1.5 text-xs">
                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-bold uppercase text-slate-500">Canonical Target:</span>
                    <span className="font-mono text-emerald-300 font-semibold">
                      {metric.source_table}.{metric.source_column}
                    </span>
                  </div>

                  <div className="flex items-center justify-between">
                    <span className="text-[10px] font-bold uppercase text-slate-500">Aggregation:</span>
                    <span className="font-mono text-indigo-300 bg-indigo-950/60 px-1.5 py-0.2 rounded border border-indigo-800/30">
                      {metric.aggregation}()
                    </span>
                  </div>

                  {metric.filter_condition && (
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-[10px] font-bold uppercase text-slate-500 shrink-0">Required Filter:</span>
                      <span className="font-mono text-amber-300 truncate max-w-[200px]" title={metric.filter_condition}>
                        WHERE {metric.filter_condition}
                      </span>
                    </div>
                  )}

                  {metric.date_column && (
                    <div className="flex items-center justify-between">
                      <span className="text-[10px] font-bold uppercase text-slate-500">Time Grain:</span>
                      <span className="font-mono text-slate-400">
                        {metric.source_table}.{metric.date_column}
                      </span>
                    </div>
                  )}
                </div>
              </div>

              {/* Card Footer: Metadata + Lineage Shortcut */}
              <div className="pt-2 border-t border-slate-800/60 flex items-center justify-between text-[11px] text-slate-500">
                <span>By {metric.creator?.name || 'Admin'}</span>
                {onSelectMetricForLineage && (
                  <button
                    onClick={() => onSelectMetricForLineage(metric.id)}
                    className="text-emerald-400 hover:text-emerald-300 font-medium flex items-center space-x-1"
                  >
                    <span>View Lineage</span>
                    <span>→</span>
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export default MetricList;
