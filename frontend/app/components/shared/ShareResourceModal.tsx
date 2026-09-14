'use client';

import React, { useState } from 'react';
import { ResourceVisibility } from '../../services/api';

interface ShareResourceModalProps {
  isOpen: boolean;
  resourceTitle: string;
  resourceType: 'Saved Query' | 'Dashboard';
  currentVisibility: ResourceVisibility;
  onClose: () => void;
  onSave: (newVisibility: ResourceVisibility) => Promise<void>;
}

export function ShareResourceModal({
  isOpen,
  resourceTitle,
  resourceType,
  currentVisibility,
  onClose,
  onSave,
}: ShareResourceModalProps) {
  const [selectedVisibility, setSelectedVisibility] = useState<ResourceVisibility>(currentVisibility);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (selectedVisibility === currentVisibility) {
      onClose();
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await onSave(selectedVisibility);
      onClose();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to update visibility.';
      setError(message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-md p-6 bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl space-y-5">
        <div className="flex items-center justify-between border-b border-slate-800 pb-3">
          <div className="flex items-center space-x-2">
            <span className="p-2 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
              </svg>
            </span>
            <div>
              <h3 className="text-base font-semibold text-white">Share {resourceType}</h3>
              <p className="text-xs text-slate-400 truncate max-w-[260px]">{resourceTitle}</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {error && (
          <div className="p-3 text-xs text-rose-300 bg-rose-950/40 border border-rose-800/60 rounded-xl">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-3">
            <label
              onClick={() => setSelectedVisibility('private')}
              className={`flex items-start p-3 rounded-xl border cursor-pointer transition-all ${
                selectedVisibility === 'private'
                  ? 'border-blue-500 bg-blue-500/10 shadow-sm shadow-blue-500/10'
                  : 'border-slate-800 bg-slate-900/50 hover:border-slate-700'
              }`}
            >
              <input
                type="radio"
                name="visibility"
                value="private"
                checked={selectedVisibility === 'private'}
                onChange={() => setSelectedVisibility('private')}
                className="mt-1 mr-3 text-blue-600 focus:ring-blue-500"
              />
              <div className="space-y-0.5">
                <div className="flex items-center space-x-2">
                  <span className="text-sm font-medium text-slate-200">Private</span>
                  <span className="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">Only You</span>
                </div>
                <p className="text-xs text-slate-400">
                  Only you and workspace administrators can view and execute this {resourceType.toLowerCase()}.
                </p>
              </div>
            </label>

            <label
              onClick={() => setSelectedVisibility('company')}
              className={`flex items-start p-3 rounded-xl border cursor-pointer transition-all ${
                selectedVisibility === 'company'
                  ? 'border-cyan-500 bg-cyan-500/10 shadow-sm shadow-cyan-500/10'
                  : 'border-slate-800 bg-slate-900/50 hover:border-slate-700'
              }`}
            >
              <input
                type="radio"
                name="visibility"
                value="company"
                checked={selectedVisibility === 'company'}
                onChange={() => setSelectedVisibility('company')}
                className="mt-1 mr-3 text-cyan-600 focus:ring-cyan-500"
              />
              <div className="space-y-0.5">
                <div className="flex items-center space-x-2">
                  <span className="text-sm font-medium text-slate-200">Company Shared</span>
                  <span className="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300 font-mono">Team</span>
                </div>
                <p className="text-xs text-slate-400">
                  Accessible by all members of your organization (Admins, Analysts, and Viewers).
                </p>
              </div>
            </label>
          </div>

          <div className="pt-3 border-t border-slate-800 flex items-center justify-end space-x-3">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-xs font-medium text-slate-300 hover:text-white bg-slate-800/80 hover:bg-slate-800 rounded-xl transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              className="px-4 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 rounded-xl shadow-lg shadow-blue-600/20 transition-all flex items-center space-x-1.5"
            >
              {saving ? (
                <>
                  <svg className="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                  </svg>
                  <span>Updating...</span>
                </>
              ) : (
                <span>Update Visibility</span>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
