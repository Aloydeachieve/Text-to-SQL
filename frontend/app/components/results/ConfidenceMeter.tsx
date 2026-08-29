import React from 'react';

export function ConfidenceMeter({ score }: { score: number | null }) {
  if (score === null) return null;

  const percentage = Math.round(score * 100);
  
  let trackColor = 'bg-emerald-500';
  let textColor = 'text-emerald-400 border-emerald-500/20 bg-emerald-500/10';
  let label = 'High';

  if (percentage < 70) {
    trackColor = 'bg-rose-500';
    textColor = 'text-rose-400 border-rose-500/20 bg-rose-500/10';
    label = 'Low';
  } else if (percentage < 90) {
    trackColor = 'bg-amber-500';
    textColor = 'text-amber-400 border-amber-500/20 bg-amber-500/10';
    label = 'Medium';
  }

  return (
    <div className="flex items-center space-x-4 p-4 rounded-xl border border-slate-850 bg-slate-950/15">
      <div className="flex-1 space-y-2">
        <div className="flex items-center justify-between text-[11px] font-medium text-slate-400">
          <span>AI Confidence Rating</span>
          <span className="font-mono font-bold text-slate-200">{percentage}%</span>
        </div>
        <div className="w-full h-1.5 bg-slate-800 rounded-full overflow-hidden">
          <div
            className={`h-full ${trackColor} transition-all duration-550`}
            style={{ width: `${percentage}%` }}
          />
        </div>
      </div>
      <span className={`text-[10px] uppercase font-bold tracking-wider px-3 py-1 rounded-full border shrink-0 ${textColor}`}>
        {label}
      </span>
    </div>
  );
}
