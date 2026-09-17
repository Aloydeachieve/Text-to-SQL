import React from 'react';
import { ExecutionInfo } from '../../services/api';

export function ResultTable({ execution }: { execution: ExecutionInfo }) {
  const { success, error, results, time_ms } = execution;

  if (!success) {
    return (
      <div className="p-4 rounded-xl bg-rose-950/20 border border-rose-800/30 text-xs text-rose-450 font-mono">
        <strong className="block mb-1 text-rose-400 font-bold">Query Execution Failed:</strong>
        {error || 'An unknown database error occurred.'}
      </div>
    );
  }

  if (results.length === 0) {
    return (
      <div className="p-8 rounded-xl bg-slate-900/10 border border-slate-800/50 text-center text-xs text-slate-500">
        Query executed successfully, but returned 0 records.
      </div>
    );
  }

  const headers = Object.keys(results[0]);

  return (
    <div className="space-y-3">
      {execution.truncated && (
        <div className="flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-950/30 border border-amber-500/30 text-amber-200 text-xs">
          <span className="font-semibold text-amber-300">⚠ Results Truncated:</span>
          <span>Showing the first {execution.returned_rows ?? results.length} rows out of {execution.total_rows?.toLocaleString() ?? '1,000+'} to safeguard memory and browser performance.</span>
        </div>
      )}

      <div className="flex items-center justify-between text-[11px] text-slate-500 px-1 font-mono">
        <div className="flex items-center gap-2">
          <span>Record Count: {results.length.toLocaleString()}</span>
          {execution.truncated && (
            <span className="px-1.5 py-0.5 rounded text-[10px] bg-amber-500/10 text-amber-400 border border-amber-500/20 font-semibold">
              Capped at {execution.limit ?? 1000}
            </span>
          )}
        </div>
        <span>Latency: {time_ms} ms</span>
      </div>

      <div className="overflow-x-auto max-h-96 overflow-y-auto rounded-xl border border-slate-850 bg-slate-950/10 custom-scrollbar">
        <table className="w-full text-left text-xs border-collapse">
          <thead>
            <tr className="border-b border-slate-850 bg-slate-950/40 text-slate-400 font-bold uppercase tracking-wider text-[10px]">
              {headers.map((h) => (
                <th key={h} className="p-3.5 font-bold">
                  {h.replace('_', ' ')}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-850/40">
            {results.map((row, idx) => (
              <tr
                key={idx}
                className="hover:bg-slate-900/10 transition-colors"
              >
                {headers.map((h) => {
                  const val = row[h];
                  let renderedVal = '';
                  if (val === null || val === undefined) {
                    renderedVal = 'NULL';
                  } else if (typeof val === 'object') {
                    renderedVal = JSON.stringify(val);
                  } else if (typeof val === 'number') {
                    if (h.toLowerCase().includes('price') || h.toLowerCase().includes('amount') || h.toLowerCase().includes('spent') || h.toLowerCase().includes('revenue')) {
                      renderedVal = `$${val.toFixed(2)}`;
                    } else {
                      renderedVal = val.toString();
                    }
                  } else {
                    renderedVal = String(val);
                  }

                  return (
                    <td
                      key={h}
                      className={`p-3.5 ${
                        val === null || val === undefined ? 'text-slate-600 italic font-mono' : 'text-slate-300 font-medium'
                      }`}
                    >
                      {renderedVal}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
