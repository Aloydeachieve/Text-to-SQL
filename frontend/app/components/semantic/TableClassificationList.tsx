'use client';

import React, { useState } from 'react';
import { TableClassification } from '../../services/api';

interface TableClassificationListProps {
  classifications: TableClassification[];
  isAdmin: boolean;
  isLoading: boolean;
  onNewClassification: () => void;
  onEditClassification: (c: TableClassification) => void;
  onDeleteClassification: (id: number) => void;
}

export function TableClassificationList({
  classifications,
  isAdmin,
  isLoading,
  onNewClassification,
  onEditClassification,
  onDeleteClassification,
}: TableClassificationListProps) {
  const [filter, setFilter] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const filtered = classifications.filter((c) => {
    const q = filter.trim().toLowerCase();
    if (!q) return true;
    return (
      c.table_name.toLowerCase().includes(q) ||
      c.classification.toLowerCase().includes(q) ||
      (c.preferred_for_concept && c.preferred_for_concept.toLowerCase().includes(q))
    );
  });

  const handleDelete = async (c: TableClassification) => {
    if (!confirm(`Delete classification for table "${c.table_name}"?`)) return;
    setDeletingId(c.id);
    try {
      await onDeleteClassification(c.id);
    } finally {
      setDeletingId(null);
    }
  };

  const getBadgeStyle = (classification: string) => {
    switch (classification) {
      case 'business':
        return 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
      case 'staging':
        return 'bg-amber-500/20 text-amber-300 border-amber-500/30';
      case 'archive':
        return 'bg-blue-500/20 text-blue-300 border-blue-500/30';
      case 'test':
        return 'bg-rose-500/20 text-rose-300 border-rose-500/30';
      case 'internal':
        return 'bg-purple-500/20 text-purple-300 border-purple-500/30';
      default:
        return 'bg-slate-800 text-slate-400 border-slate-700';
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
            placeholder="Filter by table name or classification..."
            className="w-full px-3.5 py-2 pl-9 bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors"
          />
          <span className="absolute left-3 top-2.5 text-slate-500 text-xs">🔍</span>
        </div>

        {isAdmin && (
          <button
            onClick={onNewClassification}
            className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-semibold flex items-center justify-center space-x-1.5 transition-all shadow-lg shadow-blue-600/20 shrink-0"
          >
            <span>+ Classify Table</span>
          </button>
        )}
      </div>

      {isLoading && (
        <div className="p-8 text-center text-slate-500 text-xs">
          Loading table classifications...
        </div>
      )}

      {!isLoading && filtered.length === 0 && (
        <div className="p-8 text-center rounded-2xl border border-dashed border-slate-800 bg-slate-950/40 space-y-2">
          <span className="text-2xl">🏷️</span>
          <h3 className="text-sm font-semibold text-slate-300">No Table Classifications</h3>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            Classify tables (e.g. mark staging_orders as staging, or payments as preferred revenue source) to prevent accidental queries on unverified data.
          </p>
          {isAdmin && (
            <button
              onClick={onNewClassification}
              className="mt-2 px-3 py-1.5 bg-blue-600/30 hover:bg-blue-600/50 text-blue-300 border border-blue-500/30 rounded-lg text-xs font-medium transition-all"
            >
              + Classify First Table
            </button>
          )}
        </div>
      )}

      {!isLoading && filtered.length > 0 && (
        <div className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/40">
          <table className="w-full text-left text-xs border-collapse">
            <thead>
              <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400 font-semibold uppercase text-[10px] tracking-wider">
                <th className="py-3 px-4">Table Name</th>
                <th className="py-3 px-4">Classification</th>
                <th className="py-3 px-4">Source-of-Truth Status</th>
                <th className="py-3 px-4">Usage Notes</th>
                {isAdmin && <th className="py-3 px-4 text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/60">
              {filtered.map((c) => (
                <tr key={c.id} className="hover:bg-slate-800/30 transition-colors">
                  <td className="py-3 px-4 font-mono font-bold text-slate-200">
                    {c.table_name}
                  </td>
                  <td className="py-3 px-4">
                    <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold border uppercase tracking-wider ${getBadgeStyle(c.classification)}`}>
                      {c.classification}
                    </span>
                  </td>
                  <td className="py-3 px-4">
                    {c.is_preferred_source ? (
                      <span className="inline-flex items-center space-x-1 font-semibold text-amber-300 bg-amber-950/40 px-2 py-0.5 rounded-lg border border-amber-800/40 text-[11px]">
                        <span>⭐ Preferred Source</span>
                        {c.preferred_for_concept && (
                          <span className="text-slate-400">({c.preferred_for_concept})</span>
                        )}
                      </span>
                    ) : (
                      <span className="text-slate-500 text-[11px]">Standard</span>
                    )}
                  </td>
                  <td className="py-3 px-4 text-slate-400 max-w-xs truncate" title={c.description || ''}>
                    {c.description || <span className="text-slate-600 italic">No description</span>}
                  </td>
                  {isAdmin && (
                    <td className="py-3 px-4 text-right space-x-1">
                      <button
                        onClick={() => onEditClassification(c)}
                        className="p-1 text-slate-400 hover:text-blue-400 rounded hover:bg-slate-800 transition-colors"
                        title="Edit Classification"
                      >
                        ✏️
                      </button>
                      <button
                        onClick={() => handleDelete(c)}
                        disabled={deletingId === c.id}
                        className="p-1 text-slate-400 hover:text-rose-400 rounded hover:bg-slate-800 transition-colors disabled:opacity-50"
                        title="Delete Classification"
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

export default TableClassificationList;
