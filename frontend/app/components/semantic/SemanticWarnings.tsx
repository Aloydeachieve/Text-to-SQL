'use client';

import React from 'react';
import { SemanticWarning } from '../../services/api';

interface SemanticWarningsProps {
  warnings: SemanticWarning[];
  compact?: boolean;
}

export function SemanticWarnings({ warnings, compact = false }: SemanticWarningsProps) {
  if (!warnings || warnings.length === 0) return null;

  return (
    <div className={`space-y-2 ${compact ? 'text-xs' : ''}`}>
      {warnings.map((w, idx) => {
        const isCritical = w.severity === 'critical';
        const isWarning = w.severity === 'warning';

        const borderColor = isCritical
          ? 'border-rose-500/40 bg-rose-950/30 text-rose-200'
          : isWarning
          ? 'border-amber-500/40 bg-amber-950/30 text-amber-200'
          : 'border-blue-500/40 bg-blue-950/30 text-blue-200';

        const iconColor = isCritical
          ? 'text-rose-400'
          : isWarning
          ? 'text-amber-400'
          : 'text-blue-400';

        const badgeStyle = isCritical
          ? 'bg-rose-500/20 text-rose-300 border-rose-500/30'
          : isWarning
          ? 'bg-amber-500/20 text-amber-300 border-amber-500/30'
          : 'bg-blue-500/20 text-blue-300 border-blue-500/30';

        return (
          <div
            key={idx}
            className={`p-3.5 rounded-xl border ${borderColor} flex items-start space-x-3 transition-all animate-fadeIn`}
          >
            <span className={`text-base shrink-0 mt-0.5 ${iconColor}`}>
              {isCritical ? '🛑' : isWarning ? '⚠' : 'ℹ'}
            </span>

            <div className="flex-1 space-y-1">
              <div className="flex items-center space-x-2 flex-wrap gap-1">
                <span className={`text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full border ${badgeStyle}`}>
                  {w.type.replace(/_/g, ' ')}
                </span>
                {w.table && (
                  <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-900/80 text-slate-300 border border-slate-700/50">
                    table: {w.table}
                  </span>
                )}
              </div>

              <p className="text-xs leading-relaxed font-medium">
                {w.message}
              </p>

              {w.suggestion && (
                <p className="text-[11px] text-slate-300 font-mono pt-1">
                  <span className="font-bold text-amber-300">Suggestion:</span> {w.suggestion}
                </p>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default SemanticWarnings;
