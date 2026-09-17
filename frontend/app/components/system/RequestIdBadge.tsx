'use client';

import React, { useState } from 'react';

interface RequestIdBadgeProps {
  requestId?: string | null;
  className?: string;
  compact?: boolean;
}

export const RequestIdBadge: React.FC<RequestIdBadgeProps> = ({
  requestId,
  className = '',
  compact = false,
}) => {
  const [copied, setCopied] = useState(false);

  if (!requestId) return null;

  const handleCopy = async (e: React.MouseEvent) => {
    e.stopPropagation();
    try {
      await navigator.clipboard.writeText(requestId);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Fallback
    }
  };

  const displayId = compact && requestId.length > 16
    ? `${requestId.slice(0, 10)}...${requestId.slice(-4)}`
    : requestId;

  return (
    <button
      type="button"
      onClick={handleCopy}
      title="Click to copy request correlation ID for tracing and support"
      aria-label={`Copy request correlation ID ${requestId}`}
      className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-mono transition-all duration-150 border group cursor-pointer select-none ${
        copied
          ? 'bg-emerald-950/40 border-emerald-500/40 text-emerald-300'
          : 'bg-slate-900/60 hover:bg-slate-800/80 border-slate-700/60 hover:border-slate-600 text-slate-400 hover:text-slate-200'
      } ${className}`}
    >
      <svg className="w-3 h-3 text-slate-500 group-hover:text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 9m5.918 8.93a9.96 9.96 0 01-1.988-3.93M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
      <span className="font-medium text-slate-500">ID:</span>
      <span className="truncate max-w-[140px] sm:max-w-[200px]">{displayId}</span>
      {copied ? (
        <svg className="w-3 h-3 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 13l4 4L19 7" />
        </svg>
      ) : (
        <svg className="w-2.5 h-2.5 opacity-0 group-hover:opacity-100 text-slate-400 transition-opacity shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
      )}
    </button>
  );
};
