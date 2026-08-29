import React, { useState } from 'react';

export function SqlViewer({ sql }: { sql: string | null }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    if (sql) {
      navigator.clipboard.writeText(sql);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  if (!sql) return null;

  return (
    <div className="bg-slate-950/60 rounded-xl border border-slate-800/50 overflow-hidden relative group">
      <div className="flex items-center justify-between px-4 py-2 border-b border-slate-850 bg-slate-950/40 text-[10px] font-mono text-slate-500">
        <span>GENERATED SQL QUERY</span>
        <button
          onClick={handleCopy}
          className="text-blue-450 hover:text-blue-300 font-bold transition-colors cursor-pointer"
        >
          {copied ? 'COPIED!' : 'COPY'}
        </button>
      </div>
      <div className="p-4 overflow-x-auto">
        <pre className="text-xs font-mono text-blue-200 whitespace-pre-wrap leading-relaxed">
          <code>{sql}</code>
        </pre>
      </div>
    </div>
  );
}
