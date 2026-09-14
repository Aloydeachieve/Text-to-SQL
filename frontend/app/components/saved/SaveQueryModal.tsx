'use client';

import React, { useState } from 'react';
import { createSavedQuery, SavedQuery, ResourceVisibility } from '../../services/api';

interface SaveQueryModalProps {
  isOpen: boolean;
  onClose: () => void;
  question: string;
  sql: string;
  databaseConnectionId?: number | null;
  targetDatabaseName?: string | null;
  defaultVisualizationType?: 'table' | 'bar' | 'line' | 'none' | null;
  onSaved: (savedQuery: SavedQuery) => void;
}

export function SaveQueryModal({
  isOpen,
  onClose,
  question,
  sql,
  databaseConnectionId,
  targetDatabaseName,
  defaultVisualizationType,
  onSaved,
}: SaveQueryModalProps) {
  const [name, setName] = useState(() => question.replace(/[?.,!]/g, '').trim().slice(0, 60));
  const [description, setDescription] = useState('');
  const [visualizationType, setVisualizationType] = useState<'table' | 'bar' | 'line' | 'none'>(
    defaultVisualizationType || 'bar'
  );
  const [visibility, setVisibility] = useState<ResourceVisibility>('private');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [prevQuestion, setPrevQuestion] = useState(question);
  if (question !== prevQuestion) {
    setPrevQuestion(question);
    const cleanQ = question.replace(/[?.,!]/g, '').trim();
    setName(cleanQ.slice(0, 60));
    setDescription('');
    setVisualizationType(defaultVisualizationType || 'bar');
    setVisibility('private');
    setError(null);
  }

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setError('Please enter a query name.');
      return;
    }

    setIsLoading(true);
    setError(null);

    try {
      const saved = await createSavedQuery({
        name: name.trim(),
        description: description.trim() || null,
        natural_language_question: question,
        sql,
        database_connection_id: databaseConnectionId ?? null,
        result_visualization_type: visualizationType,
        visibility,
      });

      onSaved(saved);
      onClose();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to save query.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-5">
        <div className="flex items-center justify-between border-b border-slate-800/80 pb-4">
          <div className="flex items-center space-x-2.5">
            <div className="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
              </svg>
            </div>
            <div>
              <h3 className="text-base font-bold text-white">Save Query to Analytics Library</h3>
              <p className="text-xs text-slate-400">Preserve this query for repeated execution and reporting</p>
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
              placeholder="e.g. Monthly Revenue by Region"
              required
              maxLength={150}
              className="w-full px-3.5 py-2 text-sm bg-slate-950/60 border border-slate-700/60 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-1.5">
              Description <span className="text-slate-500 font-normal">(Optional)</span>
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Context or instructions for team members..."
              rows={2}
              maxLength={500}
              className="w-full px-3.5 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 resize-none"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">
                Target Database
              </label>
              <div className="px-3 py-2 text-xs bg-slate-950/40 border border-slate-800 rounded-lg text-slate-300 truncate">
                {targetDatabaseName || 'Demo Database (MySQL)'}
              </div>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">
                Preferred Visualization
              </label>
              <select
                aria-label="Preferred Visualization"
                value={visualizationType}
                onChange={(e) => setVisualizationType(e.target.value as 'table' | 'bar' | 'line' | 'none')}
                className="w-full px-3 py-2 text-xs bg-slate-950/60 border border-slate-700/60 rounded-lg text-slate-200 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="bar">Distribution Bar</option>
                <option value="line">Trend Line</option>
                <option value="table">Table Only</option>
                <option value="none">No Chart</option>
              </select>
            </div>
          </div>

          <div>
            <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
              Visibility & Sharing
            </label>
            <div className="grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setVisibility('private')}
                className={`p-2.5 rounded-xl border text-left flex items-start space-x-2 transition-all ${
                  visibility === 'private'
                    ? 'border-blue-500 bg-blue-500/10 text-blue-300'
                    : 'border-slate-800 bg-slate-950/40 text-slate-400 hover:border-slate-700'
                }`}
              >
                <input
                  type="radio"
                  name="visibility"
                  checked={visibility === 'private'}
                  onChange={() => setVisibility('private')}
                  className="mt-0.5 text-blue-600"
                />
                <div>
                  <div className="text-xs font-semibold text-slate-200">Private</div>
                  <div className="text-[10px] text-slate-400">Only you & admins</div>
                </div>
              </button>

              <button
                type="button"
                onClick={() => setVisibility('company')}
                className={`p-2.5 rounded-xl border text-left flex items-start space-x-2 transition-all ${
                  visibility === 'company'
                    ? 'border-cyan-500 bg-cyan-500/10 text-cyan-300'
                    : 'border-slate-800 bg-slate-950/40 text-slate-400 hover:border-slate-700'
                }`}
              >
                <input
                  type="radio"
                  name="visibility"
                  checked={visibility === 'company'}
                  onChange={() => setVisibility('company')}
                  className="mt-0.5 text-cyan-600"
                />
                <div>
                  <div className="text-xs font-semibold text-slate-200">Company Shared</div>
                  <div className="text-[10px] text-slate-400">Whole organization</div>
                </div>
              </button>
            </div>
          </div>

          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="block text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                SQL Preview
              </label>
              <span className="text-[10px] text-emerald-400 font-medium">Validated & Safe</span>
            </div>
            <pre className="p-3 bg-slate-950/80 border border-slate-800/80 rounded-xl text-[11px] text-blue-300/90 font-mono overflow-x-auto max-h-24 whitespace-pre-wrap">
              {sql}
            </pre>
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
              className="px-5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 rounded-xl transition-all shadow-lg shadow-blue-500/20 flex items-center space-x-2"
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
                <span>Save to Library</span>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
