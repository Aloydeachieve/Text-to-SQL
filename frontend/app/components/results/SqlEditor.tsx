import React, { useState } from 'react';
import { Card } from '../shared/Card';

interface SqlEditorProps {
  originalSql: string;
  onRun: (editedSql: string) => void;
  isLoading?: boolean;
  readOnly?: boolean;
}

export function SqlEditor({ originalSql, onRun, isLoading, readOnly = false }: SqlEditorProps) {
  const [prevOriginalSql, setPrevOriginalSql] = useState(originalSql);
  const [sql, setSql] = useState(originalSql);

  if (originalSql !== prevOriginalSql) {
    setPrevOriginalSql(originalSql);
    setSql(originalSql);
  }

  const isEdited = sql.trim() !== originalSql.trim();

  const handleRun = () => {
    if (readOnly || !sql.trim()) return;
    onRun(sql);
  };

  const handleReset = () => {
    setSql(originalSql);
  };

  return (
    <Card className="border border-slate-800/80 bg-slate-900/10">
      <div className="flex flex-col space-y-4">
        {/* Editor Meta Row */}
        <div className="flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <span className="text-[10px] uppercase font-bold text-slate-500 tracking-wider">SQL Workspace</span>
            {readOnly ? (
              <span className="text-[10px] bg-emerald-500/15 text-emerald-400 border border-emerald-500/25 rounded-full px-2.5 py-0.5 font-bold uppercase tracking-wider">
                Read-Only (Viewer)
              </span>
            ) : isEdited ? (
              <span className="text-[10px] bg-amber-500/15 text-amber-400 border border-amber-500/25 rounded-full px-2.5 py-0.5 font-bold uppercase tracking-wider animate-pulse">
                Edited SQL
              </span>
            ) : (
              <span className="text-[10px] bg-blue-500/15 text-blue-400 border border-blue-500/25 rounded-full px-2.5 py-0.5 font-bold uppercase tracking-wider">
                AI Generated SQL
              </span>
            )}
          </div>
          {isEdited && !readOnly && (
            <button
              onClick={handleReset}
              disabled={isLoading}
              className="text-[11px] text-slate-400 hover:text-white transition-colors flex items-center space-x-1 group"
            >
              <svg className="w-3.5 h-3.5 transform group-hover:rotate-45 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89M9 11l3-3 3 3m-3-3v12" />
              </svg>
              <span className="group-hover:underline">Reset to AI SQL</span>
            </button>
          )}
        </div>

        {/* Text Area */}
        <div className="relative">
          <textarea
            value={sql}
            onChange={(e) => {
              if (!readOnly) setSql(e.target.value);
            }}
            readOnly={readOnly}
            disabled={isLoading}
            className={`w-full h-32 font-mono text-xs p-4 bg-slate-950/60 border border-slate-850 rounded-xl text-slate-205 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/50 resize-none transition-all leading-relaxed ${
              readOnly ? 'cursor-default opacity-80' : ''
            }`}
            placeholder="SELECT * FROM table..."
          />
        </div>

        {/* Actions Block */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
          <p className="text-[10px] text-slate-500 leading-normal max-w-md">
            {readOnly
              ? 'Viewers cannot re-execute arbitrary SQL from the workspace. Pre-approved queries can be executed via Saved Queries.'
              : 'Query workspace runs custom edits through Laravel security validation. Destructive modifications or invalid column paths will be automatically blocked.'}
          </p>
          {!readOnly && (
            <button
              onClick={handleRun}
              disabled={isLoading || !sql.trim()}
              className="px-4 py-2 text-xs font-bold bg-blue-600 hover:bg-blue-500 text-white rounded-lg transition-all shadow-lg hover:shadow-blue-500/10 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center space-x-1.5 shrink-0"
            >
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
              </svg>
              <span>{isEdited ? 'Execute Custom SQL' : 'Execute SQL'}</span>
            </button>
          )}
        </div>
      </div>
    </Card>
  );
}
export default SqlEditor;
