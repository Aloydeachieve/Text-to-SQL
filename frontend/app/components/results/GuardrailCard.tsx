import React from 'react';
import { GuardrailsInfo } from '../../services/api';

export function GuardrailCard({ info }: { info: GuardrailsInfo }) {
  const { allowed, reason } = info;

  return (
    <div
      className={`p-4 rounded-xl border flex items-start space-x-3 transition-all duration-200 ${
        allowed
          ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
          : 'bg-rose-500/10 border-rose-500/20 text-rose-400'
      }`}
    >
      <div className="mt-0.5 shrink-0">
        {allowed ? (
          <svg className="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
        ) : (
          <svg className="w-5 h-5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        )}
      </div>

      <div className="space-y-1">
        <h4 className="text-xs font-bold uppercase tracking-wider">
          {allowed ? 'Security Check: APPROVED' : 'Security Check: BLOCKED'}
        </h4>
        <p className="text-xs text-slate-350 leading-relaxed">
          {allowed
            ? 'The query passed static analysis: Only read-only SELECT statements are permitted, preventing SQL injection and destructive operations.'
            : reason || 'Operation blocked due to policy violations.'}
        </p>
      </div>
    </div>
  );
}
