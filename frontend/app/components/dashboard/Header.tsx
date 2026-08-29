import React from 'react';

export function Header() {
  return (
    <header className="w-full py-5 px-6 border-b border-slate-800/30 bg-slate-950/20 backdrop-blur-md flex items-center justify-between sticky top-0 z-50">
      <div className="flex items-center space-x-3">
        <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-blue-600 to-violet-600 flex items-center justify-center font-bold text-white shadow-lg shadow-blue-500/20">
          SQL
        </div>
        <div>
          <h1 className="text-lg font-bold tracking-tight text-white flex items-center">
            Text-to-SQL 
            <span className="text-[10px] font-medium text-blue-400 border border-blue-500/30 rounded-full px-2 py-0.5 ml-3 bg-blue-950/30">
              Guardrails Engaged
            </span>
          </h1>
        </div>
      </div>
      
      <div className="flex items-center space-x-4">
        <div className="flex items-center space-x-2 text-xs text-slate-400 bg-slate-900/60 px-3 py-1.5 rounded-full border border-slate-800/50">
          <span className="relative flex h-2 w-2">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
          </span>
          <span>Local Engine Active</span>
        </div>
      </div>
    </header>
  );
}
