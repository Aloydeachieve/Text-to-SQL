'use client';

import React, { useState } from 'react';
import { SemanticTerm, SemanticMetric, CreateTermParams, UpdateTermParams, SemanticTermTargetType } from '../../services/api';

interface BusinessTermModalProps {
  isOpen: boolean;
  term?: SemanticTerm | null;
  metrics: SemanticMetric[];
  onClose: () => void;
  onSave: (params: CreateTermParams | UpdateTermParams) => Promise<void>;
}

const TARGET_TYPES: { value: SemanticTermTargetType; label: string; description: string }[] = [
  { value: 'metric', label: 'Metric', description: 'Maps term to a canonical business metric' },
  { value: 'table', label: 'Table', description: 'Maps term directly to a database table' },
  { value: 'column', label: 'Column', description: 'Maps term to a specific database column' },
  { value: 'filter', label: 'Filter', description: 'Term implies a specific WHERE filter' },
  { value: 'concept', label: 'Concept (Ambiguous)', description: 'Ambiguous business term requiring user clarification' },
];

export function BusinessTermModal({ isOpen, term, metrics, onClose, onSave }: BusinessTermModalProps) {
  if (!isOpen) return null;

  return (
    <BusinessTermModalInner
      key={term?.id ?? 'new'}
      term={term}
      metrics={metrics}
      onClose={onClose}
      onSave={onSave}
    />
  );
}

function BusinessTermModalInner({ term, metrics, onClose, onSave }: Omit<BusinessTermModalProps, 'isOpen'>) {
  const defaultMetric = metrics.length > 0 ? metrics[0] : null;
  const [termText, setTermText] = useState(term?.term ?? '');
  const [targetType, setTargetType] = useState<SemanticTermTargetType>(term?.target_type ?? 'metric');
  const [metricId, setMetricId] = useState<number | ''>(term ? (term.metric_id ?? '') : (defaultMetric ? defaultMetric.id : ''));
  const [targetName, setTargetName] = useState(term ? term.target_name : (defaultMetric ? defaultMetric.name : ''));
  const [definition, setDefinition] = useState(term?.definition ?? '');

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEditing = Boolean(term);

  const handleMetricSelect = (mId: number) => {
    setMetricId(mId);
    const m = metrics.find((x) => x.id === mId);
    if (m) setTargetName(m.name);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!termText.trim() || !targetName.trim()) {
      setError('Term and Target Name are required.');
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      await onSave({
        term: termText.trim().toLowerCase(),
        target_type: targetType,
        target_name: targetName.trim(),
        metric_id: targetType === 'metric' && metricId ? Number(metricId) : null,
        definition: definition.trim() || null,
      });
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to save terminology mapping.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
        {/* Modal Header */}
        <div className="p-5 border-b border-slate-800 flex items-center justify-between bg-slate-950/50">
          <div className="flex items-center space-x-2.5">
            <span className="p-2 rounded-xl bg-purple-500/20 text-purple-400">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
              </svg>
            </span>
            <div>
              <h2 className="text-base font-bold text-white">
                {isEditing ? `Edit Term: "${term?.term}"` : 'Add Business Terminology Mapping'}
              </h2>
              <p className="text-xs text-slate-400">
                Map business jargon, synonyms, and domain concepts to schema structures
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

        {/* Modal Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {error && (
            <div className="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 text-xs flex items-center space-x-2">
              <span>⚠</span>
              <span>{error}</span>
            </div>
          )}

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Term / Synonym <span className="text-purple-400">*</span>
            </label>
            <input
              type="text"
              required
              value={termText}
              onChange={(e) => setTermText(e.target.value)}
              placeholder="e.g. sales turnover, active users, inventory"
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-purple-500"
            />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                Target Type <span className="text-purple-400">*</span>
              </label>
              <select
                value={targetType}
                onChange={(e) => {
                  const val = e.target.value as SemanticTermTargetType;
                  setTargetType(val);
                  if (val === 'metric' && metrics.length > 0) {
                    setMetricId(metrics[0].id);
                    setTargetName(metrics[0].name);
                  }
                }}
                className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 focus:outline-none focus:border-purple-500"
              >
                {TARGET_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-300 mb-1">
                {targetType === 'metric' ? 'Select Metric' : 'Target Identifier'} <span className="text-purple-400">*</span>
              </label>
              {targetType === 'metric' ? (
                <select
                  value={metricId}
                  onChange={(e) => handleMetricSelect(Number(e.target.value))}
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 focus:outline-none focus:border-purple-500"
                >
                  <option value="" disabled>Select a metric...</option>
                  {metrics.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.source_table}.{m.source_column})
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  required
                  value={targetName}
                  onChange={(e) => setTargetName(e.target.value)}
                  placeholder={
                    targetType === 'table'
                      ? 'e.g. products'
                      : targetType === 'column'
                      ? 'e.g. total_price'
                      : targetType === 'filter'
                      ? "e.g. status = 'active'"
                      : 'e.g. active_customer_rule'
                  }
                  className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-purple-500"
                />
              )}
            </div>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Definition / Clarification Note
            </label>
            <textarea
              rows={2}
              value={definition}
              onChange={(e) => setDefinition(e.target.value)}
              placeholder="e.g. Synonym for gross collected payments, or clarification message when ambiguous."
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-purple-500 resize-none"
            />
          </div>

          {/* Footer Buttons */}
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
              className="px-5 py-2 text-xs font-semibold bg-purple-600 hover:bg-purple-500 text-white rounded-xl transition-all shadow-lg shadow-purple-600/20 disabled:opacity-50"
            >
              {isSubmitting ? 'Saving...' : isEditing ? 'Update Mapping' : 'Create Mapping'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default BusinessTermModal;
