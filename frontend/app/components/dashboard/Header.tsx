import React from 'react';
import { AuthUser } from '../../services/api';
import { RoleBadge } from '../team/RoleBadge';

interface HeaderProps {
  currentUser?: AuthUser | null;
  onOpenConnectionsModal?: () => void;
  activeView?: 'workspace' | 'saved_queries' | 'dashboards' | 'team' | 'semantic';
  onViewChange?: (view: 'workspace' | 'saved_queries' | 'dashboards' | 'team' | 'semantic') => void;
  savedQueriesCount?: number;
  dashboardsCount?: number;
}

export function Header({
  currentUser,
  onOpenConnectionsModal,
  activeView = 'workspace',
  onViewChange,
  savedQueriesCount = 0,
  dashboardsCount = 0,
}: HeaderProps) {
  const isAdmin = !currentUser || currentUser.role === 'admin';

  return (
    <header className="w-full py-3.5 px-6 border-b border-slate-800/30 bg-slate-950/40 backdrop-blur-md flex items-center justify-between sticky top-0 z-50">
      <div className="flex items-center space-x-3">
        <div className="w-9 h-9 rounded-xl bg-gradient-to-tr from-blue-600 to-violet-600 flex items-center justify-center font-bold text-white shadow-lg shadow-blue-500/20">
          SQL
        </div>
        <div>
          <h1 className="text-base font-bold tracking-tight text-white flex items-center flex-wrap gap-2">
            Text-to-SQL 
            <span className="text-[10px] font-medium text-blue-400 border border-blue-500/30 rounded-full px-2 py-0.5 bg-blue-950/30">
              Guardrails Engaged
            </span>
            {currentUser?.company && (
              <span className="text-[10px] font-medium text-purple-300 border border-purple-500/30 rounded-full px-2 py-0.5 bg-purple-950/30">
                🏢 {currentUser.company.name}
              </span>
            )}
            {currentUser?.role && (
              <RoleBadge role={currentUser.role} size="sm" />
            )}
          </h1>
        </div>
      </div>

      {/* Navigation Tabs */}
      {onViewChange && (
        <nav aria-label="Main Navigation" className="flex items-center bg-slate-900/80 p-1 rounded-xl border border-slate-800">
          <button
            type="button"
            onClick={() => onViewChange('workspace')}
            className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeView === 'workspace'
                ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>Workspace</span>
          </button>

          <button
            type="button"
            onClick={() => onViewChange('saved_queries')}
            className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeView === 'saved_queries'
                ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" />
            </svg>
            <span>Saved Queries</span>
            {savedQueriesCount > 0 && (
              <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-bold ${
                activeView === 'saved_queries' ? 'bg-blue-700 text-white' : 'bg-slate-800 text-slate-300'
              }`}>
                {savedQueriesCount}
              </span>
            )}
          </button>

          <button
            type="button"
            onClick={() => onViewChange('dashboards')}
            className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
              activeView === 'dashboards'
                ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/20'
                : 'text-slate-400 hover:text-white'
            }`}
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
            </svg>
            <span>Dashboards</span>
            {dashboardsCount > 0 && (
              <span className={`text-[10px] px-1.5 py-0.2 rounded-full font-bold ${
                activeView === 'dashboards' ? 'bg-blue-700 text-white' : 'bg-slate-800 text-slate-300'
              }`}>
                {dashboardsCount}
              </span>
            )}
          </button>

          {currentUser && (
            <button
              type="button"
              onClick={() => onViewChange('team')}
              className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                activeView === 'team'
                  ? 'bg-purple-600 text-white shadow-sm shadow-purple-500/20'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
              <span>Team</span>
            </button>
          )}

          {currentUser && (
            <button
              type="button"
              onClick={() => onViewChange('semantic')}
              className={`flex items-center space-x-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                activeView === 'semantic'
                  ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-500/20'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
              <span>Semantic Model</span>
            </button>
          )}
        </nav>
      )}
      
      <div className="flex items-center space-x-3">
        {onOpenConnectionsModal && isAdmin && (
          <button
            onClick={onOpenConnectionsModal}
            className="flex items-center space-x-2 text-xs font-semibold text-blue-300 bg-blue-950/40 hover:bg-blue-900/50 px-3.5 py-1.5 rounded-full border border-blue-800/50 hover:border-blue-700 transition-all shadow-sm"
          >
            <svg className="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3m-16 5c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3" />
            </svg>
            <span>{currentUser ? 'Databases' : 'Connect Database'}</span>
          </button>
        )}

        <div className="flex items-center space-x-2 text-xs text-slate-400 bg-slate-900/60 px-3 py-1.5 rounded-full border border-slate-800/50">
          <span className="relative flex h-2 w-2">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
          </span>
          <span>Engine Active</span>
        </div>
      </div>
    </header>
  );
}

