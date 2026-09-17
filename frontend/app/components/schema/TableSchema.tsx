import React, { useState } from 'react';
import { TableInfo, RelationshipInfo, TableClassification, SemanticMetric } from '../../services/api';

interface TableSchemaProps {
  table: TableInfo;
  relationships: RelationshipInfo[];
  forceOpen?: boolean | null;
  searchFilter?: string;
  classification?: TableClassification | null;
  associatedMetrics?: SemanticMetric[];
}

export function TableSchema({
  table,
  relationships,
  forceOpen = null,
  searchFilter = '',
  classification = null,
  associatedMetrics = [],
}: TableSchemaProps) {
  const [userToggledOpen, setUserToggledOpen] = useState<boolean | null>(null);

  const queryTerm = searchFilter.trim().toLowerCase();
  const hasSearchMatch = queryTerm.length > 0 && (
    table.name.toLowerCase().includes(queryTerm) ||
    table.columns.some(c => c.name.toLowerCase().includes(queryTerm))
  );

  // Derive isOpen cleanly: user toggle takes precedence, then forceOpen, then search match
  const isOpen = userToggledOpen !== null
    ? userToggledOpen
    : (forceOpen !== null ? forceOpen : hasSearchMatch);

  // Filter relationships involving this table
  const tableRels = relationships.filter(
    rel =>
      rel.from.startsWith(table.name + '.') ||
      rel.to.startsWith(table.name + '.') ||
      rel.from_table === table.name ||
      rel.to_table === table.name
  );

  const getClassificationBadge = (cls: string) => {
    switch (cls) {
      case 'staging':
        return 'bg-amber-500/20 text-amber-300 border-amber-500/30';
      case 'archive':
        return 'bg-blue-500/20 text-blue-300 border-blue-500/30';
      case 'test':
        return 'bg-rose-500/20 text-rose-300 border-rose-500/30';
      case 'internal':
        return 'bg-purple-500/20 text-purple-300 border-purple-500/30';
      case 'business':
        return 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
      default:
        return 'bg-slate-800 text-slate-400 border-slate-700';
    }
  };

  return (
    <div className="border border-slate-850 rounded-xl overflow-hidden bg-slate-900/10 transition-all duration-200">
      <button
        onClick={() => setUserToggledOpen(!isOpen)}
        className="w-full flex items-center justify-between p-3.5 hover:bg-slate-850/30 transition-colors text-left"
      >
        <div className="flex items-center space-x-2 flex-wrap gap-1">
          <span className="text-slate-400">
            <svg className="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </span>
          <span className="font-semibold text-sm text-slate-200 font-mono">
            {table.name}
          </span>
          <span className="text-[10px] bg-slate-800 text-slate-400 rounded px-1.5 py-0.5 font-mono">
            {table.columns.length} cols
          </span>

          {/* Table Classification Badge */}
          {classification && (
            <span className={`text-[10px] font-bold px-2 py-0.2 rounded-full border uppercase tracking-wider ${getClassificationBadge(classification.classification)}`}>
              {classification.classification}
            </span>
          )}

          {/* Preferred Source Indicator */}
          {classification?.is_preferred_source && (
            <span className="text-[10px] font-bold px-2 py-0.2 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">
              ⭐ {classification.preferred_for_concept || 'Source of Truth'}
            </span>
          )}
        </div>
        <span className="text-slate-500">
          <svg
            className={`w-4 h-4 transform transition-transform duration-250 ${isOpen ? 'rotate-180' : ''}`}
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
          </svg>
        </span>
      </button>

      {isOpen && (
        <div className="p-4 border-t border-slate-850/50 bg-slate-900/20 space-y-3.5">
          {/* Business Metadata Callout (if classified or associated with metrics) */}
          {(classification?.description || associatedMetrics.length > 0) && (
            <div className="p-3 bg-slate-950/60 rounded-xl border border-slate-800 text-xs space-y-1.5">
              {classification?.description && (
                <div>
                  <span className="text-[10px] font-bold uppercase text-slate-500 block">Business Description:</span>
                  <p className="text-slate-300">{classification.description}</p>
                </div>
              )}
              {associatedMetrics.length > 0 && (
                <div>
                  <span className="text-[10px] font-bold uppercase text-emerald-400 block">Canonical Metrics Sourced:</span>
                  <div className="flex flex-wrap gap-1.5 pt-0.5">
                    {associatedMetrics.map((m) => (
                      <span key={m.id} className="font-mono text-[10px] bg-emerald-950/60 text-emerald-300 px-2 py-0.5 rounded border border-emerald-800/40">
                        {m.name} → {m.aggregation}({m.source_column})
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
          {/* Column Details */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">
              <span>Columns</span>
              <span>Type & Constraint</span>
            </div>
            {table.columns.map((col, idx) => {
              const isPK = col.primary || col.type.toLowerCase().includes('pk') || col.name === 'id';
              const isFK = col.foreign || col.type.toLowerCase().includes('fk') || Boolean(col.referenced_table) || col.name.endsWith('_id');
              const isMatched = queryTerm.length > 0 && col.name.toLowerCase().includes(queryTerm);

              return (
                <div
                  key={idx}
                  className={`flex items-center justify-between text-xs py-1.5 px-2 rounded-lg border-b border-slate-850/20 last:border-0 transition-colors ${
                    isMatched ? 'bg-indigo-500/10 border-indigo-500/30' : 'hover:bg-slate-800/20'
                  }`}
                >
                  <div className="flex items-center space-x-1.5 min-w-0">
                    {isPK && <span className="text-[11px] text-amber-500 shrink-0" title="Primary Key">🔑</span>}
                    {isFK && <span className="text-[11px] text-blue-400 shrink-0" title="Foreign Key">🔗</span>}
                    <span className={`font-mono font-medium truncate ${isMatched ? 'text-indigo-200 font-bold' : 'text-slate-300'}`}>
                      {col.name}
                    </span>
                    {col.referenced_table && (
                      <span className="text-[9px] font-mono text-blue-400/80 bg-blue-950/40 px-1 py-0.5 rounded border border-blue-800/30 truncate max-w-[120px]" title={`References ${col.referenced_table}.${col.referenced_column || 'id'}`}>
                        → {col.referenced_table}.{col.referenced_column || 'id'}
                      </span>
                    )}
                  </div>

                  <div className="flex items-center space-x-2 shrink-0">
                    {col.nullable !== undefined && (
                      <span className={`text-[9px] font-mono px-1 py-0.5 rounded ${col.nullable ? 'text-slate-500' : 'text-slate-400 bg-slate-800/40'}`}>
                        {col.nullable ? 'null' : 'not null'}
                      </span>
                    )}
                    <span className="font-mono text-[10px] text-slate-400 bg-slate-900/60 px-1.5 py-0.5 rounded border border-slate-800/50">
                      {col.type}
                    </span>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Table Relations */}
          {tableRels.length > 0 && (
            <div className="space-y-1.5 pt-3 border-t border-slate-850/30">
              <div className="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Relationships</div>
              {tableRels.map((rel, idx) => (
                <div key={idx} className="text-[10px] bg-slate-950/40 rounded-lg p-2.5 border border-slate-850/40 text-slate-400 space-y-1 font-mono">
                  <div className="flex items-center justify-between">
                    <span className="text-blue-400 font-medium">{rel.from}</span>
                    <span className="text-slate-600">→</span>
                    <span className="text-slate-300 font-medium">{rel.to}</span>
                  </div>
                  <div className="text-[9px] text-slate-500 text-right italic font-sans">{rel.label}</div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default TableSchema;
