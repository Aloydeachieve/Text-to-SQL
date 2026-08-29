import React from 'react';
import { HistoryItem } from '../../services/api';

interface HistorySidebarProps {
  history: HistoryItem[];
  isLoading: boolean;
  onRefresh: () => void;
  onSelectItem: (item: HistoryItem) => void;
  selectedId?: number;
}

export function HistorySidebar({
  history,
  isLoading,
  onRefresh,
  onSelectItem,
  selectedId
}: HistorySidebarProps) {
  return (
    <aside className="w-80 border-r border-slate-850 bg-slate-950/15 flex flex-col h-[calc(100vh-77px)] shrink-0">
      <div className="p-4 border-b border-slate-850 flex items-center justify-between">
        <h2 className="text-xs font-bold tracking-wider uppercase text-slate-400">Query Log</h2>
        <button
          onClick={onRefresh}
          disabled={isLoading}
          className="text-xs text-blue-400 hover:text-blue-300 font-medium transition-colors disabled:opacity-50 flex items-center gap-1"
        >
          {isLoading ? (
            <svg className="animate-spin h-3.5 w-3.5" viewBox="0 0 24 24" fill="none">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
          ) : 'Refresh'}
        </button>
      </div>

      <div className="flex-1 overflow-y-auto p-3 space-y-2">
        {isLoading && history.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-slate-500 gap-2">
            <svg className="animate-spin h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
            </svg>
            <span className="text-xs">Loading history...</span>
          </div>
        ) : history.length === 0 ? (
          <div className="text-center text-xs text-slate-550 py-12">
            No queries logged yet.
          </div>
        ) : (
          history.map((item) => {
            const isSelected = selectedId === item.id;
            
            let statusColor = 'bg-slate-800 text-slate-400';
            let statusLabel = 'Failed';
            
            if (item.execution_status === 'success') {
              statusColor = 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20';
              statusLabel = 'Success';
            } else if (item.execution_status === 'blocked') {
              statusColor = 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
              statusLabel = 'Blocked';
            }

            return (
              <button
                key={item.id}
                onClick={() => onSelectItem(item)}
                className={`w-full text-left p-3.5 rounded-xl transition-all duration-200 border flex flex-col space-y-2 ${
                  isSelected
                    ? 'bg-blue-600/10 border-blue-500/40 shadow-sm shadow-blue-500/5'
                    : 'bg-slate-900/20 border-slate-900/50 hover:bg-slate-900/40 hover:border-slate-800/60'
                }`}
              >
                <div className="flex items-center justify-between">
                  <span className={`text-[9px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider ${statusColor}`}>
                    {statusLabel}
                  </span>
                  <span className="text-[10px] text-slate-500 font-mono">
                    {new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                  </span>
                </div>
                
                <p className="text-xs font-medium text-slate-300 line-clamp-2 leading-relaxed">
                  {item.question}
                </p>

                <div className="flex items-center justify-between text-[10px] text-slate-500 font-mono pt-1">
                  {item.execution_time_ms !== null ? (
                    <span>{item.execution_time_ms} ms</span>
                  ) : (
                    <span />
                  )}
                  {item.confidence_score !== null && (
                    <span>Conf: {Math.round(item.confidence_score * 100)}%</span>
                  )}
                </div>
              </button>
            );
          })
        )}
      </div>
    </aside>
  );
}
