'use client';

import React, { useState } from 'react';
import { updateSavedQuery, SavedQuery } from '../../services/api';

interface EditSavedQueryModalProps {
  isOpen: boolean;
  onClose: () => void;
  savedQuery: SavedQuery | null;
  onUpdated: (savedQuery: SavedQuery) => void;
}

export function EditSavedQueryModal({
  isOpen,
  onClose,
  savedQuery,
  onUpdated,
}: EditSavedQueryModalProps) {
  const [name, setName] = useState(savedQuery?.name || '');
  const [description, setDescription] = useState(savedQuery?.description || '');
  const [visualizationType, setVisualizationType] = useState<'table' | 'bar' | 'line' | 'none'>(
    savedQuery?.result_visualization_type || 'bar'
  );
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [prevQuery, setPrevQuery] = useState<SavedQuery | null>(savedQuery);
  if (savedQuery !== prevQuery) {
    setPrevQuery(savedQuery);
    setName(savedQuery?.name || '');
    setDescription(savedQuery?.description || '');
    setVisualizationType(savedQuery?.result_visualization_type || 'bar');
    setError(null);
  }

  if (!isOpen || !savedQuery) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setError('Please enter a query name.');
      return;
    }

    setIsLoading(true);
    setError(null);

    try {
      const updated = await updateSavedQuery(savedQuery.id, {
        name: name.trim(),
        description: description.trim() || null,
        result_visualization_type: visualizationType,
      });

      onUpdated(updated);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to update query.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-5">
        <div className="flex items-center justify-between border-b border-slate-800/80 pb-4">
          <div className="flex items-center space-x-2.5">
            <div className="w-8 h-8 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </div>
            <div>
              <h3 className="text-base font-bold text-white">Edit Saved Query</h3>
              <p className="text-xs text-slate-400">Update query metadata and display settings</p>
            </div>
          </div>
          <button
            onClick={onClose}
            aria-label="Close modal"
            className="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {error && (
          <div className="p-3 rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
              Query Name <span className="text-rose-400">*</span>
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              maxLength={150}
              className="w-full px-3.5 py-2 text-sm bg-slate-950/60 border border-slate-700/60 rounded-xl text-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
              Description <span className="text-slate-500 font-normal">(Optional)</span>
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              maxLength={500}
              className="w-full px-3.5 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-xl text-white focus:outline-none focus:ring-1 focus:ring-indigo-500 resize-none"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
              Preferred Visualization
            </label>
            <select
              aria-label="Preferred Visualization"
              value={visualizationType}
              onChange={(e) => setVisualizationType(e.target.value as 'table' | 'bar' | 'line' | 'none')}
              className="w-full px-3 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-lg text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
              <option value="bar">Distribution Bar</option>
              <option value="line">Trend Line</option>
              <option value="table">Table Only</option>
              <option value="none">No Chart</option>
            </select>
          </div>

          <div className="flex items-center justify-end space-x-3 pt-3 border-t border-slate-800/60">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-xs font-medium text-slate-400 hover:text-white transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isLoading}
              className="px-5 py-2 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 rounded-xl transition-all shadow-lg shadow-indigo-500/20 flex items-center space-x-2"
            >
              {isLoading ? (
                <>
                  <svg className="animate-spin h-3.5 w-3.5 text-white" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                  </svg>
                  <span>Saving...</span>
                </>
              ) : (
                <span>Update Query</span>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
