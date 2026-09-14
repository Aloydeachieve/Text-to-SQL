'use client';

import React, { useState } from 'react';
import { SavedQuery, AddWidgetParams } from '../../services/api';

interface AddWidgetModalProps {
  isOpen: boolean;
  onClose: () => void;
  savedQueries: SavedQuery[];
  onAddWidget: (params: AddWidgetParams) => Promise<void>;
  onNavigateToWorkspace?: () => void;
}

export function AddWidgetModal({
  isOpen,
  onClose,
  savedQueries,
  onAddWidget,
  onNavigateToWorkspace,
}: AddWidgetModalProps) {
  const [selectedQueryId, setSelectedQueryId] = useState<number | ''>(
    savedQueries.length > 0 ? savedQueries[0].id : ''
  );
  const [title, setTitle] = useState('');
  const [visualizationType, setVisualizationType] = useState<'metric' | 'bar' | 'line' | 'table'>('bar');
  const [width, setWidth] = useState<number>(1);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const selectedQuery = savedQueries.find((q) => q.id === selectedQueryId);

  const handleQueryChange = (id: number) => {
    setSelectedQueryId(id);
    const query = savedQueries.find((q) => q.id === id);
    if (query) {
      // Auto-populate visualization type if saved query has a preference
      if (query.result_visualization_type === 'bar' || query.result_visualization_type === 'line' || query.result_visualization_type === 'table') {
        setVisualizationType(query.result_visualization_type);
      }
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedQueryId) {
      setError('Please select a saved query.');
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      await onAddWidget({
        saved_query_id: Number(selectedQueryId),
        title: title.trim() || null,
        visualization_type: visualizationType,
        width: width,
      });
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to add widget.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm animate-fadeIn">
      <div className="w-full max-w-xl bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl p-6 space-y-6 animate-scaleUp max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-slate-800 pb-4">
          <div className="flex items-center space-x-3">
            <div className="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
            </div>
            <div>
              <h3 className="text-base font-bold text-white">Add Widget to Dashboard</h3>
              <p className="text-xs text-slate-400">Select a saved query to display as a live data card</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Error Notification */}
        {error && (
          <div className="p-3 bg-rose-500/10 border border-rose-500/20 rounded-xl text-rose-400 text-xs flex items-center space-x-2">
            <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{error}</span>
          </div>
        )}

        {savedQueries.length === 0 ? (
          <div className="py-8 text-center space-y-3">
            <div className="w-12 h-12 mx-auto rounded-full bg-slate-800/80 flex items-center justify-center text-slate-400">
              <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <p className="text-sm font-medium text-slate-300">No saved queries available</p>
            <p className="text-xs text-slate-500 max-w-sm mx-auto">
              You must save at least one query in your analytics library before adding widgets to this dashboard.
            </p>
            {onNavigateToWorkspace && (
              <button
                type="button"
                onClick={() => {
                  onClose();
                  onNavigateToWorkspace();
                }}
                className="mt-2 px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all"
              >
                Go to Workspace
              </button>
            )}
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            {/* 1. Select Saved Query */}
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-slate-300">
                Data Source (Saved Query) <span className="text-rose-400">*</span>
              </label>
              <select
                value={selectedQueryId}
                onChange={(e) => handleQueryChange(Number(e.target.value))}
                className="w-full px-3.5 py-2.5 text-xs bg-slate-950/60 border border-slate-800 rounded-xl text-white focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 cursor-pointer"
              >
                {savedQueries.map((q) => (
                  <option key={q.id} value={q.id}>
                    {q.name} &bull; ({q.is_demo ? 'Demo DB' : q.target_database_name || 'Customer DB'})
                  </option>
                ))}
              </select>

              {/* Query Preview Card */}
              {selectedQuery && (
                <div className="p-3 mt-2 rounded-xl bg-slate-950/40 border border-slate-800/80 space-y-2 text-xs">
                  <div className="flex items-center justify-between text-[11px] text-slate-400">
                    <span>Question: &ldquo;{selectedQuery.natural_language_question}&rdquo;</span>
                    <span className="px-1.5 py-0.5 rounded bg-blue-500/10 text-blue-400 font-mono text-[10px]">
                      {selectedQuery.is_demo ? 'Demo MySQL' : selectedQuery.target_database_name}
                    </span>
                  </div>
                  <pre className="font-mono text-[11px] text-emerald-400/90 bg-slate-900/80 p-2 rounded-lg overflow-x-auto whitespace-pre-wrap">
                    {selectedQuery.sql}
                  </pre>
                </div>
              )}
            </div>

            {/* 2. Custom Title Override */}
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-slate-300">
                Widget Title <span className="text-slate-500 font-normal">(Optional — defaults to query name)</span>
              </label>
              <input
                type="text"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder={selectedQuery?.name || 'e.g. Monthly Revenue'}
                className="w-full px-3.5 py-2 text-xs bg-slate-950/60 border border-slate-800 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-all"
              />
            </div>

            {/* 3. Visualization Type */}
            <div className="space-y-2">
              <label className="text-xs font-semibold text-slate-300">
                Visualization Type
              </label>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                {[
                  { id: 'metric', label: 'KPI / Metric', icon: '🔢', desc: 'Single big number' },
                  { id: 'bar', label: 'Bar Chart', icon: '📊', desc: 'Categorical compare' },
                  { id: 'line', label: 'Line Chart', icon: '📈', desc: 'Trend over time' },
                  { id: 'table', label: 'Data Table', icon: '📋', desc: 'Rows and columns' },
                ].map((type) => (
                  <button
                    key={type.id}
                    type="button"
                    onClick={() => setVisualizationType(type.id as 'metric' | 'bar' | 'line' | 'table')}
                    className={`p-3 rounded-xl border text-left flex flex-col justify-between transition-all ${
                      visualizationType === type.id
                        ? 'bg-blue-600/20 border-blue-500 text-white shadow-sm'
                        : 'bg-slate-950/40 border-slate-800 text-slate-400 hover:text-slate-200 hover:border-slate-700'
                    }`}
                  >
                    <div className="text-lg">{type.icon}</div>
                    <div className="mt-2">
                      <div className="text-xs font-bold leading-tight">{type.label}</div>
                      <div className="text-[10px] text-slate-500 mt-0.5">{type.desc}</div>
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {/* 4. Grid Width */}
            <div className="space-y-2">
              <label className="text-xs font-semibold text-slate-300">
                Widget Width
              </label>
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setWidth(1)}
                  className={`p-3 rounded-xl border text-left flex items-center space-x-3 transition-all ${
                    width === 1
                      ? 'bg-blue-600/20 border-blue-500 text-white shadow-sm'
                      : 'bg-slate-950/40 border-slate-800 text-slate-400 hover:text-slate-200 hover:border-slate-700'
                  }`}
                >
                  <div className="w-6 h-6 rounded border border-current flex items-center justify-center text-[10px] font-bold">
                    ½
                  </div>
                  <div>
                    <div className="text-xs font-bold">Half Width</div>
                    <div className="text-[10px] text-slate-500">1 column (compact)</div>
                  </div>
                </button>

                <button
                  type="button"
                  onClick={() => setWidth(2)}
                  className={`p-3 rounded-xl border text-left flex items-center space-x-3 transition-all ${
                    width === 2
                      ? 'bg-blue-600/20 border-blue-500 text-white shadow-sm'
                      : 'bg-slate-950/40 border-slate-800 text-slate-400 hover:text-slate-200 hover:border-slate-700'
                  }`}
                >
                  <div className="w-8 h-6 rounded border border-current flex items-center justify-center text-[10px] font-bold">
                    1/1
                  </div>
                  <div>
                    <div className="text-xs font-bold">Full Width</div>
                    <div className="text-[10px] text-slate-500">Spans full grid width</div>
                  </div>
                </button>
              </div>
            </div>

            {/* Actions */}
            <div className="flex items-center justify-end space-x-3 pt-4 border-t border-slate-800">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition-colors"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={isSubmitting}
                className="px-5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 rounded-xl transition-all shadow-lg shadow-blue-500/20 disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2"
              >
                {isSubmitting && (
                  <svg className="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                )}
                <span>Add Widget</span>
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
