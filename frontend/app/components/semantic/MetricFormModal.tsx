'use client';

import React, { useState } from 'react';
import { SemanticMetric, CreateMetricParams, UpdateMetricParams } from '../../services/api';

interface MetricFormModalProps {
  isOpen: boolean;
  metric?: SemanticMetric | null;
  onClose: () => void;
  onSave: (params: CreateMetricParams | UpdateMetricParams) => Promise<void>;
}

const AGGREGATION_OPTIONS = [
  { value: 'SUM', label: 'SUM (Sum of values)' },
  { value: 'COUNT', label: 'COUNT (Count of rows)' },
  { value: 'AVG', label: 'AVG (Average)' },
  { value: 'MIN', label: 'MIN (Minimum)' },
  { value: 'MAX', label: 'MAX (Maximum)' },
  { value: 'COUNT_DISTINCT', label: 'COUNT_DISTINCT (Distinct count)' },
];

export function MetricFormModal({ isOpen, metric, onClose, onSave }: MetricFormModalProps) {
  if (!isOpen) return null;

  return (
    <MetricFormModalInner
      key={metric?.id ?? 'new'}
      metric={metric}
      onClose={onClose}
      onSave={onSave}
    />
  );
}

function MetricFormModalInner({ metric, onClose, onSave }: Omit<MetricFormModalProps, 'isOpen'>) {
  const [name, setName] = useState(metric?.name ?? '');
  const [slug, setSlug] = useState(metric?.slug ?? '');
  const [description, setDescription] = useState(metric?.description ?? '');
  const [definition, setDefinition] = useState(metric?.definition ?? '');
  const [sourceTable, setSourceTable] = useState(metric?.source_table ?? '');
  const [sourceColumn, setSourceColumn] = useState(metric?.source_column ?? '');
  const [aggregation, setAggregation] = useState(metric?.aggregation ?? 'SUM');
  const [filterCondition, setFilterCondition] = useState(metric?.filter_condition ?? '');
  const [dateColumn, setDateColumn] = useState(metric?.date_column ?? '');
  const [isSourceOfTruth, setIsSourceOfTruth] = useState(Boolean(metric ? metric.is_source_of_truth : true));
  const [isActive, setIsActive] = useState(Boolean(metric ? metric.is_active : true));

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEditing = Boolean(metric);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !definition.trim() || !sourceTable.trim() || !sourceColumn.trim()) {
      setError('Please fill in Name, Definition, Source Table, and Source Column.');
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      await onSave({
        name: name.trim(),
        slug: slug.trim() || undefined,
        description: description.trim() || null,
        definition: definition.trim(),
        source_table: sourceTable.trim().toLowerCase(),
        source_column: sourceColumn.trim().toLowerCase(),
        aggregation,
        filter_condition: filterCondition.trim() || null,
        date_column: dateColumn.trim() ? dateColumn.trim().toLowerCase() : null,
        is_source_of_truth: isSourceOfTruth,
        is_active: isActive,
      });
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to save business metric.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xl shadow-2xl overflow-hidden max-h-[92vh] flex flex-col">
        {/* Modal Header */}
        <div className="p-5 border-b border-slate-800 flex items-center justify-between bg-slate-950/50">
          <div className="flex items-center space-x-2.5">
            <span className="p-2 rounded-xl bg-emerald-500/20 text-emerald-400">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
            </span>
            <div>
              <h2 className="text-base font-bold text-white">
                {isEditing ? `Edit Metric: ${metric?.name}` : 'Define Canonical Business Metric'}
              </h2>
              <p className="text-xs text-slate-400">
                Configure source-of-truth calculations and rules for Text-to-SQL accuracy
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition-colors"
          >
            ✕
          </button>
        </div>

        {/* Modal Body */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4 overflow-y-auto flex-1">
          {error && (
            <div className="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs flex items-center space-x-2">
              <span>⚠</span>
              <span>{error}</span>
            </div>
          )}

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Metric Name <span className="text-emerald-400">*</span>
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Revenue, Active Customers"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Slug (Optional)
              </label>
              <input
                type="text"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                placeholder="e.g. revenue, active_customers"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Business Definition <span className="text-emerald-400">*</span>
            </label>
            <textarea
              required
              rows={2}
              value={definition}
              onChange={(e) => setDefinition(e.target.value)}
              placeholder="e.g. Completed customer payments recognized as revenue."
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-emerald-500 resize-none"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Description (Optional)
            </label>
            <input
              type="text"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="e.g. Detailed notes for reporting analysts."
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-emerald-500"
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Source Table <span className="text-emerald-400">*</span>
              </label>
              <input
                type="text"
                required
                value={sourceTable}
                onChange={(e) => setSourceTable(e.target.value)}
                placeholder="e.g. payments"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 font-mono placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Source Column <span className="text-emerald-400">*</span>
              </label>
              <input
                type="text"
                required
                value={sourceColumn}
                onChange={(e) => setSourceColumn(e.target.value)}
                placeholder="e.g. amount"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 font-mono placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Aggregation <span className="text-emerald-400">*</span>
              </label>
              <select
                value={aggregation}
                onChange={(e) => setAggregation(e.target.value as SemanticMetric['aggregation'])}
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 focus:outline-none focus:border-emerald-500"
              >
                {AGGREGATION_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Required Filter (WHERE clause)
              </label>
              <input
                type="text"
                value={filterCondition}
                onChange={(e) => setFilterCondition(e.target.value)}
                placeholder="e.g. status = 'completed'"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 font-mono placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Date Column (For time grain)
              </label>
              <input
                type="text"
                value={dateColumn}
                onChange={(e) => setDateColumn(e.target.value)}
                placeholder="e.g. paid_at, created_at"
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 font-mono placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          {/* Toggles */}
          <div className="pt-2 flex items-center justify-between border-t border-slate-800">
            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isSourceOfTruth}
                onChange={(e) => setIsSourceOfTruth(e.target.checked)}
                className="w-4 h-4 rounded text-emerald-500 bg-slate-950 border-slate-800 focus:ring-0"
              />
              <span className="text-xs text-slate-300">
                ⭐ Mark as Canonical Source of Truth
              </span>
            </label>

            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isActive}
                onChange={(e) => setIsActive(e.target.checked)}
                className="w-4 h-4 rounded text-emerald-500 bg-slate-950 border-slate-800 focus:ring-0"
              />
              <span className="text-xs text-slate-300">Active</span>
            </label>
          </div>

          {/* Footer Buttons */}
          <div className="flex items-center justify-end space-x-3 pt-4">
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
              className="px-5 py-2 text-xs font-semibold bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl transition-all shadow-lg shadow-emerald-600/20 disabled:opacity-50"
            >
              {isSubmitting ? 'Saving...' : isEditing ? 'Update Metric' : 'Create Metric'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default MetricFormModal;
