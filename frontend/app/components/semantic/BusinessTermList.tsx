'use client';

import React, { useState } from 'react';
import { SemanticTerm } from '../../services/api';

interface BusinessTermListProps {
  terms: SemanticTerm[];
  isAdmin: boolean;
  isLoading: boolean;
  onNewTerm: () => void;
  onEditTerm: (term: SemanticTerm) => void;
  onDeleteTerm: (id: number) => void;
}

export function BusinessTermList({
  terms,
  isAdmin,
  isLoading,
  onNewTerm,
  onEditTerm,
  onDeleteTerm,
}: BusinessTermListProps) {
  const [filter, setFilter] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const filteredTerms = terms.filter((t) => {
    const q = filter.trim().toLowerCase();
    if (!q) return true;
    return (
      t.term.toLowerCase().includes(q) ||
      t.target_name.toLowerCase().includes(q) ||
      t.target_type.toLowerCase().includes(q)
    );
  });

  const handleDelete = async (term: SemanticTerm) => {
    if (!confirm(`Delete terminology mapping for "${term.term}"?`)) return;
    setDeletingId(term.id);
    try {
      await onDeleteTerm(term.id);
    } finally {
      setDeletingId(null);
    }
  };

  const getTargetTypeBadge = (type: string) => {
    switch (type) {
      case 'metric':
        return 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
      case 'table':
        return 'bg-blue-500/20 text-blue-300 border-blue-500/30';
      case 'column':
        return 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30';
      case 'filter':
        return 'bg-amber-500/20 text-amber-300 border-amber-500/30';
      case 'concept':
        return 'bg-purple-500/20 text-purple-300 border-purple-500/30';
      default:
        return 'bg-slate-800 text-slate-400';
    }
  };

  return (
    <div className="space-y-4">
      {/* Search & Actions Bar */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div className="relative flex-1 max-w-md">
          <input
            type="text"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder="Filter terms or target concepts..."
            className="w-full px-3.5 py-2 pl-9 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-purple-500 transition-colors"
          />
          <span className="absolute left-3 top-2.5 text-slate-500 text-xs">🔍</span>
        </div>

        {isAdmin && (
          <button
            onClick={onNewTerm}
            className="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-semibold flex items-center justify-center space-x-1.5 transition-all shadow-lg shadow-purple-600/20 shrink-0"
          >
            <span>+ Add Term Mapping</span>
          </button>
        )}
      </div>

      {isLoading && (
        <div className="p-8 text-center text-slate-500 text-xs">
          Loading terminology mappings...
        </div>
      )}

      {!isLoading && filteredTerms.length === 0 && (
        <div className="p-8 text-center rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 space-y-2">
          <span className="text-2xl">📖</span>
          <h3 className="text-sm font-semibold text-slate-300">No Terminology Mappings</h3>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            Map company-specific phrases (e.g. &ldquo;sales&rdquo; → Revenue, &ldquo;active customer&rdquo; → definition) to prevent ambiguous queries.
          </p>
          {isAdmin && (
            <button
              onClick={onNewTerm}
              className="mt-2 px-3 py-1.5 bg-purple-600/30 hover:bg-purple-600/50 text-purple-300 border border-purple-500/30 rounded-lg text-xs font-medium transition-all"
            >
              + Add First Mapping
            </button>
          )}
        </div>
      )}

      {!isLoading && filteredTerms.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/40">
          <table className="w-full text-left text-xs border-collapse">
            <thead>
              <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400 font-semibold uppercase text-[10px] tracking-wider">
                <th className="py-3 px-4">Business Term</th>
                <th className="py-3 px-4">Target Type</th>
                <th className="py-3 px-4">Resolves To</th>
                <th className="py-3 px-4">Description / Rule</th>
                {isAdmin && <th className="py-3 px-4 text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/60">
              {filteredTerms.map((t) => (
                <tr key={t.id} className="hover:bg-slate-800/30 transition-colors">
                  <td className="py-3 px-4 font-bold text-slate-200">
                    &ldquo;{t.term}&rdquo;
                  </td>
                  <td className="py-3 px-4">
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold border uppercase tracking-wider ${getTargetTypeBadge(t.target_type)}`}>
                      {t.target_type}
                    </span>
                  </td>
                  <td className="py-3 px-4 font-mono font-medium text-slate-300">
                    {t.target_type === 'metric' && t.metric ? (
                      <span className="text-emerald-300 font-bold">
                        {t.metric.name} ({t.metric.source_table}.{t.metric.source_column})
                      </span>
                    ) : (
                      t.target_name
                    )}
                  </td>
                  <td className="py-3 px-4 text-slate-400 max-w-xs truncate" title={t.definition || ''}>
                    {t.definition || <span className="text-slate-600 italic">No notes</span>}
                  </td>
                  {isAdmin && (
                    <td className="py-3 px-4 text-right space-x-1">
                      <button
                        onClick={() => onEditTerm(t)}
                        className="p-1 text-slate-400 hover:text-purple-400 rounded hover:bg-slate-800 transition-colors"
                        title="Edit Term"
                      >
                        ✏️
                      </button>
                      <button
                        onClick={() => handleDelete(t)}
                        disabled={deletingId === t.id}
                        className="p-1 text-slate-400 hover:text-rose-400 rounded hover:bg-slate-800 transition-colors disabled:opacity-50"
                        title="Delete Term"
                      >
                        🗑️
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default BusinessTermList;
