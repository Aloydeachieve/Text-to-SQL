'use client';

import React, { useState, useEffect } from 'react';
import { fetchLineageGraph, LineageGraphData, SemanticMetric } from '../../services/api';

interface LineageGraphViewProps {
  metrics: SemanticMetric[];
  selectedMetricId?: number | null;
  onSelectMetric?: (id: number | null) => void;
}

export function LineageGraphView({
  metrics,
  selectedMetricId = null,
  onSelectMetric,
}: LineageGraphViewProps) {
  const [internalMetricId, setInternalMetricId] = useState<number | null>(null);
  const metricId = selectedMetricId !== undefined ? selectedMetricId : internalMetricId;
  const [graph, setGraph] = useState<LineageGraphData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let ignore = false;

    fetchLineageGraph(metricId ?? undefined)
      .then((data) => {
        if (!ignore) {
          setGraph(data);
          setIsLoading(false);
        }
      })
      .catch((err: unknown) => {
        if (!ignore) {
          setError(err instanceof Error ? err.message : 'Failed to fetch data lineage.');
          setIsLoading(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [metricId]);

  const handleMetricChange = (id: number | null) => {
    setIsLoading(true);
    setError(null);
    if (onSelectMetric) {
      onSelectMetric(id);
    } else {
      setInternalMetricId(id);
    }
  };

  const getNodeColor = (type: string) => {
    switch (type) {
      case 'metric':
        return 'border-emerald-500/50 bg-emerald-950/40 text-emerald-200';
      case 'column':
        return 'border-indigo-500/50 bg-indigo-950/40 text-indigo-200';
      case 'table':
        return 'border-blue-500/50 bg-blue-950/40 text-blue-200';
      case 'filter':
        return 'border-amber-500/50 bg-amber-950/40 text-amber-200';
      case 'date_column':
        return 'border-cyan-500/50 bg-cyan-950/40 text-cyan-200';
      default:
        return 'border-slate-700 bg-slate-900 text-slate-300';
    }
  };

  const getNodeIcon = (type: string) => {
    switch (type) {
      case 'metric':
        return '📊';
      case 'column':
        return '🔹';
      case 'table':
        return '🗄️';
      case 'filter':
        return '⚡';
      case 'date_column':
        return '📅';
      default:
        return '•';
    }
  };

  return (
    <div className="space-y-4">
      {/* Selector & Legend Bar */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-slate-950/60 p-4 rounded-2xl border border-slate-800">
        <div className="flex items-center space-x-3">
          <span className="text-xs font-semibold text-slate-300">Target Metric:</span>
          <select
            value={metricId ?? ''}
            onChange={(e) => handleMetricChange(e.target.value ? Number(e.target.value) : null)}
            className="px-3 py-1.5 bg-slate-900 border border-slate-700 rounded-xl text-xs text-slate-200 focus:outline-none focus:border-emerald-500 font-medium"
          >
            <option value="">All Company Metrics</option>
            {metrics.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name} ({m.source_table}.{m.source_column})
              </option>
            ))}
          </select>
        </div>

        {/* Legend */}
        <div className="flex items-center space-x-3 text-[11px] text-slate-400 flex-wrap gap-2">
          <span className="inline-flex items-center space-x-1">
            <span className="w-2 h-2 rounded-full bg-emerald-400" />
            <span>Metric</span>
          </span>
          <span className="inline-flex items-center space-x-1">
            <span className="w-2 h-2 rounded-full bg-indigo-400" />
            <span>Column</span>
          </span>
          <span className="inline-flex items-center space-x-1">
            <span className="w-2 h-2 rounded-full bg-blue-400" />
            <span>Table</span>
          </span>
          <span className="inline-flex items-center space-x-1">
            <span className="w-2 h-2 rounded-full bg-amber-400" />
            <span>Filter</span>
          </span>
          <span className="inline-flex items-center space-x-1">
            <span className="w-2 h-2 rounded-full bg-cyan-400" />
            <span>Time Grain</span>
          </span>
        </div>
      </div>

      {isLoading && (
        <div className="p-12 text-center text-slate-500 text-xs">
          Tracing semantic data lineage...
        </div>
      )}

      {error && (
        <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs">
          ⚠ {error}
        </div>
      )}

      {!isLoading && (!graph || graph.nodes.length === 0) && (
        <div className="p-12 text-center rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 space-y-2">
          <span className="text-2xl">🕸️</span>
          <h3 className="text-sm font-semibold text-slate-300">No Lineage Paths Available</h3>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            Create canonical metrics to view their end-to-end data lineage paths to database tables and join relationships.
          </p>
        </div>
      )}

      {/* Lineage Graph Visualizer */}
      {!isLoading && graph && graph.nodes.length > 0 && (
        <div className="space-y-4">
          {/* Visual Node Grid */}
          <div className="p-6 bg-slate-950/50 rounded-2xl border border-slate-800 overflow-x-auto">
            <div className="min-w-[600px] space-y-6">
              {/* Grouped by Node Types */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {/* 1. Business Concept Level */}
                <div className="space-y-3">
                  <h4 className="text-[11px] font-bold uppercase tracking-wider text-emerald-400 flex items-center space-x-1.5 border-b border-emerald-500/20 pb-1.5">
                    <span>1. Business Metrics</span>
                  </h4>
                  <div className="space-y-2.5">
                    {graph.nodes
                      .filter((n) => n.type === 'metric')
                      .map((node) => (
                        <div
                          key={node.id}
                          className={`p-3 rounded-xl border ${getNodeColor(node.type)} shadow-sm space-y-1.5`}
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-bold text-xs flex items-center space-x-1.5">
                              <span>{getNodeIcon(node.type)}</span>
                              <span>{node.label}</span>
                            </span>
                            {node.is_source_of_truth && (
                              <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                ⭐ SOT
                              </span>
                            )}
                          </div>
                          {node.definition && (
                            <p className="text-[11px] text-slate-300 leading-tight">
                              {node.definition}
                            </p>
                          )}
                          <div className="text-[10px] font-mono text-emerald-300">
                            Agg: {node.aggregation}()
                          </div>
                        </div>
                      ))}
                  </div>
                </div>

                {/* 2. Field & Constraint Level */}
                <div className="space-y-3">
                  <h4 className="text-[11px] font-bold uppercase tracking-wider text-indigo-400 flex items-center space-x-1.5 border-b border-indigo-500/20 pb-1.5">
                    <span>2. Columns & Constraints</span>
                  </h4>
                  <div className="space-y-2.5">
                    {graph.nodes
                      .filter((n) => n.type === 'column' || n.type === 'filter' || n.type === 'date_column')
                      .map((node) => (
                        <div
                          key={node.id}
                          className={`p-3 rounded-xl border ${getNodeColor(node.type)} shadow-sm space-y-1`}
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-mono text-xs font-semibold flex items-center space-x-1.5">
                              <span>{getNodeIcon(node.type)}</span>
                              <span className="truncate max-w-[170px]" title={node.label}>
                                {node.label}
                              </span>
                            </span>
                            <span className="text-[9px] uppercase px-1.5 py-0.2 rounded bg-slate-900 border border-slate-700/50">
                              {node.type.replace('_', ' ')}
                            </span>
                          </div>
                          {node.condition && (
                            <p className="text-[10px] font-mono text-amber-300/90 truncate" title={node.condition}>
                              WHERE {node.condition}
                            </p>
                          )}
                        </div>
                      ))}
                  </div>
                </div>

                {/* 3. Physical Storage Level */}
                <div className="space-y-3">
                  <h4 className="text-[11px] font-bold uppercase tracking-wider text-blue-400 flex items-center space-x-1.5 border-b border-blue-500/20 pb-1.5">
                    <span>3. Source Tables</span>
                  </h4>
                  <div className="space-y-2.5">
                    {graph.nodes
                      .filter((n) => n.type === 'table')
                      .map((node) => (
                        <div
                          key={node.id}
                          className={`p-3 rounded-xl border ${getNodeColor(node.type)} shadow-sm space-y-1.5`}
                        >
                          <div className="flex items-center justify-between">
                            <span className="font-mono font-bold text-xs flex items-center space-x-1.5">
                              <span>{getNodeIcon(node.type)}</span>
                              <span>{node.label}</span>
                            </span>
                            <span className="text-[9px] uppercase font-bold px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700/60">
                              {node.classification || 'business'}
                            </span>
                          </div>
                          {node.is_preferred_source && (
                            <span className="text-[10px] font-semibold text-amber-300 block">
                              ⭐ Preferred Source Table
                            </span>
                          )}
                        </div>
                      ))}
                  </div>
                </div>
              </div>

              {/* Edge Relationships Table */}
              <div className="pt-4 border-t border-slate-800">
                <h5 className="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">
                  Lineage Data Flow & Join Edges ({graph.edges.length})
                </h5>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs">
                  {graph.edges.map((edge, idx) => (
                    <div
                      key={idx}
                      className="p-2.5 rounded-xl bg-slate-900/60 border border-slate-800/80 flex items-center space-x-2 font-mono text-[11px]"
                    >
                      <span className="text-slate-300 font-semibold truncate max-w-[140px]" title={edge.source}>
                        {edge.source.replace(/^(metric|table|col|filter)_/, '')}
                      </span>
                      <span className="text-emerald-400 shrink-0">──[{edge.label}]──▶</span>
                      <span className="text-slate-300 font-semibold truncate max-w-[140px]" title={edge.target}>
                        {edge.target.replace(/^(metric|table|col|filter)_/, '')}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

export default LineageGraphView;
