import React, { useState } from 'react';
import { TableInfo, RelationshipInfo } from '../../services/api';

interface TableSchemaProps {
  table: TableInfo;
  relationships: RelationshipInfo[];
}

export function TableSchema({ table, relationships }: TableSchemaProps) {
  const [isOpen, setIsOpen] = useState(false);

  // Filter relationships involving this table
  const tableRels = relationships.filter(
    rel => rel.from.startsWith(table.name + '.') || rel.to.startsWith(table.name + '.')
  );

  return (
    <div className="border border-slate-850 rounded-xl overflow-hidden bg-slate-900/10 transition-all duration-200">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex items-center justify-between p-3.5 hover:bg-slate-850/30 transition-colors text-left"
      >
        <div className="flex items-center space-x-2">
          <span className="text-slate-400">
            <svg className="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </span>
          <span className="font-semibold text-sm text-slate-205 capitalize">{table.name}</span>
          <span className="text-[10px] bg-slate-800 text-slate-400 rounded px-1.5 py-0.5 font-mono">
            {table.columns.length} columns
          </span>
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
          {/* Column Details */}
          <div className="space-y-1.5">
            <div className="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Columns</div>
            {table.columns.map((col, idx) => {
              const isPK = col.type.toLowerCase().includes('pk') || col.name === 'id';
              const isFK = col.type.toLowerCase().includes('fk') || col.name.endsWith('_id');
              return (
                <div key={idx} className="flex items-center justify-between text-xs py-1.5 border-b border-slate-850/20 last:border-0">
                  <div className="flex items-center space-x-1.5">
                    {isPK && <span className="text-[10px] text-amber-500" title="Primary Key">🔑</span>}
                    {isFK && <span className="text-[10px] text-blue-400" title="Foreign Key">🔗</span>}
                    <span className="font-mono text-slate-300 font-medium">{col.name}</span>
                  </div>
                  <span className="font-mono text-[10px] text-slate-500">{col.type}</span>
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
