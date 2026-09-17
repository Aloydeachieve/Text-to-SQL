'use client';

import React, { useState } from 'react';
import { TableClassification, SaveClassificationParams, TableClassificationType } from '../../services/api';

interface TableClassificationModalProps {
  isOpen: boolean;
  classification?: TableClassification | null;
  onClose: () => void;
  onSave: (params: SaveClassificationParams) => Promise<void>;
}

const CLASSIFICATION_OPTIONS: { value: TableClassificationType; label: string; description: string; color: string }[] = [
  { value: 'business', label: 'Business (Production)', description: 'Official canonical production tables for reporting', color: 'text-emerald-400' },
  { value: 'staging', label: 'Staging', description: 'Temporary pre-ingestion tables; not for official reporting', color: 'text-amber-400' },
  { value: 'archive', label: 'Archive', description: 'Historical data tables; may be outdated', color: 'text-blue-400' },
  { value: 'test', label: 'Test / Scratch', description: 'Mock or test data; generates critical warnings', color: 'text-rose-400' },
  { value: 'internal', label: 'Internal / System', description: 'Internal audit or telemetry logs', color: 'text-purple-400' },
  { value: 'unknown', label: 'Unknown', description: 'Unclassified table', color: 'text-slate-400' },
];

export function TableClassificationModal({ isOpen, classification, onClose, onSave }: TableClassificationModalProps) {
  if (!isOpen) return null;

  return (
    <TableClassificationModalInner
      key={classification?.id ?? 'new'}
      classification={classification}
      onClose={onClose}
      onSave={onSave}
    />
  );
}

function TableClassificationModalInner({ classification, onClose, onSave }: Omit<TableClassificationModalProps, 'isOpen'>) {
  const [tableName, setTableName] = useState(classification?.table_name ?? '');
  const [type, setType] = useState<TableClassificationType>(classification?.classification ?? 'business');
  const [description, setDescription] = useState(classification?.description ?? '');
  const [isPreferredSource, setIsPreferredSource] = useState(Boolean(classification?.is_preferred_source));
  const [preferredForConcept, setPreferredForConcept] = useState(classification?.preferred_for_concept ?? '');

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEditing = Boolean(classification);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!tableName.trim()) {
      setError('Table name is required.');
      return;
    }

    setIsSubmitting(true);
    setError(null);

    try {
      await onSave({
        table_name: tableName.trim().toLowerCase(),
        classification: type,
        description: description.trim() || null,
        is_preferred_source: isPreferredSource,
        preferred_for_concept: isPreferredSource && preferredForConcept.trim() ? preferredForConcept.trim() : null,
      });
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to save table classification.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">
        {/* Modal Header */}
        <div className="p-5 border-b border-slate-800 flex items-center justify-between bg-slate-950/50">
          <div className="flex items-center space-x-2.5">
            <span className="p-2 rounded-xl bg-blue-500/20 text-blue-400">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
            </span>
            <div>
              <h2 className="text-base font-bold text-white">
                {isEditing ? `Classify Table: ${classification?.table_name}` : 'Classify Database Table'}
              </h2>
              <p className="text-xs text-slate-400">
                Categorize table usage & establish source-of-truth preferences
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
              Table Name <span className="text-blue-400">*</span>
            </label>
            <input
              type="text"
              required
              disabled={isEditing}
              value={tableName}
              onChange={(e) => setTableName(e.target.value)}
              placeholder="e.g. staging_orders, payments, archived_logs"
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 font-mono placeholder-slate-500 focus:outline-none focus:border-blue-500 disabled:opacity-50"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Classification Category <span className="text-blue-400">*</span>
            </label>
            <select
              value={type}
              onChange={(e) => setType(e.target.value as TableClassificationType)}
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 focus:outline-none focus:border-blue-500"
            >
              {CLASSIFICATION_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
            <p className="text-[11px] text-slate-400 mt-1">
              {CLASSIFICATION_OPTIONS.find((o) => o.value === type)?.description}
            </p>
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 mb-1">
              Usage Description / Purpose
            </label>
            <textarea
              rows={2}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="e.g. Holds raw webhook dumps before overnight verification ETL."
              className="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-blue-500 resize-none"
            />
          </div>

          {/* Preferred Source Toggle */}
          <div className="p-3.5 bg-slate-950/60 rounded-xl border border-slate-800/80 space-y-2.5">
            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isPreferredSource}
                onChange={(e) => setIsPreferredSource(e.target.checked)}
                className="w-4 h-4 rounded text-blue-500 bg-slate-950 border-slate-800 focus:ring-0"
              />
              <span className="text-xs font-semibold text-slate-200">
                ⭐ Preferred Source-of-Truth Table
              </span>
            </label>

            {isPreferredSource && (
              <div>
                <label className="block text-[11px] font-medium text-slate-400 mb-1">
                  Preferred For Business Concept
                </label>
                <input
                  type="text"
                  value={preferredForConcept}
                  onChange={(e) => setPreferredForConcept(e.target.value)}
                  placeholder="e.g. Revenue, Active Customers, Official Orders"
                  className="w-full px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-lg text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-blue-500"
                />
              </div>
            )}
          </div>

          {/* Footer Buttons */}
          <div className="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800">
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
              className="px-5 py-2 text-xs font-semibold bg-blue-600 hover:bg-blue-500 text-white rounded-xl transition-all shadow-lg shadow-blue-600/20 disabled:opacity-50"
            >
              {isSubmitting ? 'Saving...' : 'Save Classification'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default TableClassificationModal;
