import React, { useState, useEffect } from 'react';
import { TableSchema } from './TableSchema';
import { fetchSchema, SchemaDetails } from '../../services/api';
import { Card } from '../shared/Card';
import { Loader } from '../shared/Loader';

export function SchemaExplorer() {
  const [schema, setSchema] = useState<SchemaDetails | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    fetchSchema()
      .then(res => {
        if (res.success) {
          setSchema(res.data);
        } else {
          setError('Failed to fetch database schema information.');
        }
      })
      .catch(err => {
        setError(err.message || 'Failed to load schema.');
      })
      .finally(() => {
        setIsLoading(false);
      });
  }, []);

  return (
    <Card title="Database Schema Explorer" className="h-full">
      {isLoading && <Loader message="Querying schema structure..." />}
      
      {error && (
        <div className="p-3 text-xs rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-450">
          <span className="font-bold">Error:</span> {error}
        </div>
      )}

      {schema && (
        <div className="space-y-4">
          <p className="text-xs text-slate-400 leading-relaxed">
            Expand tables below to explore mapped database columns, data types, and primary/foreign key connections.
          </p>
          <div className="space-y-3 max-h-[460px] overflow-y-auto pr-1 custom-scrollbar">
            {schema.tables.map((table, idx) => (
              <TableSchema key={idx} table={table} relationships={schema.relationships} />
            ))}
          </div>
        </div>
      )}
    </Card>
  );
}
export default SchemaExplorer;
