import React, { useState, useEffect, useMemo } from 'react';
import { TableSchema } from './TableSchema';
import { fetchSchema, fetchConnectionSchema, SchemaDetails } from '../../services/api';
import { Card } from '../shared/Card';
import { Loader } from '../shared/Loader';

interface SchemaExplorerProps {
  activeDatabaseConnectionId?: number | null;
  activeDatabaseName?: string;
}

export function SchemaExplorer({
  activeDatabaseConnectionId = null,
  activeDatabaseName = 'Demo Database (MySQL)'
}: SchemaExplorerProps) {
  const [schema, setSchema] = useState<SchemaDetails | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [forceOpen, setForceOpen] = useState<boolean | null>(null);

  useEffect(() => {
    let ignore = false;

    const promise = activeDatabaseConnectionId !== null && activeDatabaseConnectionId !== undefined
      ? fetchConnectionSchema(activeDatabaseConnectionId)
      : fetchSchema().then(res => res.data);

    promise
      .then(data => {
        if (!ignore) {
          setSchema(data);
          setError(null);
        }
      })
      .catch(err => {
        if (!ignore) {
          setError(err.message || 'Failed to load schema.');
        }
      })
      .finally(() => {
        if (!ignore) {
          setIsLoading(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [activeDatabaseConnectionId]);

  // Compute filtered tables based on search query (table name or column name)
  const filteredTables = useMemo(() => {
    if (!schema) return [];
    const q = searchQuery.trim().toLowerCase();
    if (!q) return schema.tables;

    return schema.tables.filter(table => {
      const matchesTable = table.name.toLowerCase().includes(q);
      const matchesColumn = table.columns.some(col => col.name.toLowerCase().includes(q));
      return matchesTable || matchesColumn;
    });
  }, [schema, searchQuery]);

  // Total columns count across all tables
  const totalColumns = useMemo(() => {
    if (!schema) return 0;
    return schema.tables.reduce((acc, t) => acc + t.columns.length, 0);
  }, [schema]);

  return (
    <Card title="Database Schema Explorer" className="h-full">
      {/* Active Database Badge Header */}
      <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-800/40">
        <div className="flex items-center space-x-2">
          <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
          <span className="text-xs font-semibold text-slate-300 truncate max-w-[170px]" title={activeDatabaseName}>
            {activeDatabaseName}
          </span>
        </div>
        {schema && (
          <div className="flex items-center space-x-1.5 text-[11px] font-mono text-slate-400">
            <span className="bg-slate-800/60 px-2 py-0.5 rounded border border-slate-750">
              {filteredTables.length} of {schema.tables.length} {schema.tables.length === 1 ? 'tbl' : 'tbls'}
            </span>
          </div>
        )}
      </div>

      {isLoading && <Loader message="Querying schema structure..." />}
      
      {error && (
        <div className="p-3 text-xs rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-400">
          <span className="font-bold">Error:</span> {error}
        </div>
      )}

      {schema && (
        <div className="space-y-3">
          {/* Search Filter and Expand/Collapse Controls */}
          <div className="space-y-2">
            <div className="relative">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setForceOpen(null);
                }}
                placeholder="Search tables or columns..."
                className="w-full bg-slate-950/60 border border-slate-800 rounded-lg px-3 py-1.5 pl-8 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500/60 transition-colors"
              />
              <svg className="w-3.5 h-3.5 text-slate-500 absolute left-2.5 top-2.5 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              {searchQuery && (
                <button
                  onClick={() => setSearchQuery('')}
                  className="absolute right-2.5 top-2 text-slate-500 hover:text-slate-300 text-xs"
                >
                  ✕
                </button>
              )}
            </div>

            <div className="flex items-center justify-between text-[11px] text-slate-500 px-0.5">
              <span>{totalColumns} mapped columns</span>
              <div className="flex items-center space-x-2">
                <button
                  onClick={() => setForceOpen(true)}
                  className="hover:text-indigo-300 transition-colors"
                >
                  Expand all
                </button>
                <span>•</span>
                <button
                  onClick={() => setForceOpen(false)}
                  className="hover:text-indigo-300 transition-colors"
                >
                  Collapse all
                </button>
              </div>
            </div>
          </div>

          {/* Tables List */}
          {filteredTables.length === 0 ? (
            <div className="py-8 text-center text-xs text-slate-500 bg-slate-900/20 rounded-xl border border-slate-800/40">
              {searchQuery ? (
                <>No tables or columns match &ldquo;<span className="text-slate-400">{searchQuery}</span>&rdquo;</>
              ) : (
                'No tables found in this database.'
              )}
            </div>
          ) : (
            <div className="space-y-2.5 max-h-[440px] overflow-y-auto pr-1 custom-scrollbar">
              {filteredTables.map((table, idx) => (
                <TableSchema
                  key={`${table.name}-${idx}`}
                  table={table}
                  relationships={schema.relationships}
                  forceOpen={forceOpen}
                  searchFilter={searchQuery}
                />
              ))}
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

export default SchemaExplorer;
