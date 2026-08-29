'use client';

import React, { useState, useEffect } from 'react';
import { Header } from './components/dashboard/Header';
import { HistorySidebar } from './components/dashboard/HistorySidebar';
import { QueryInput } from './components/query/QueryInput';
import { Card } from './components/shared/Card';
import { Loader } from './components/shared/Loader';
import { SqlEditor } from './components/results/SqlEditor';
import { GuardrailCard } from './components/results/GuardrailCard';
import { ResultTable } from './components/results/ResultTable';
import { ConfidenceMeter } from './components/results/ConfidenceMeter';
import { ChartResult } from './components/results/ChartResult';
import { SchemaExplorer } from './components/schema/SchemaExplorer';
import { submitQuery, fetchHistory, QueryResponse, HistoryItem } from './services/api';

export default function Dashboard() {
  const [history, setHistory] = useState<HistoryItem[]>([]);
  const [isHistoryLoading, setIsHistoryLoading] = useState(false);
  
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeResult, setActiveResult] = useState<QueryResponse | null>(null);
  const [selectedHistoryId, setSelectedHistoryId] = useState<number | undefined>(undefined);

  const loadHistory = async (showLoading = false) => {
    if (showLoading) setIsHistoryLoading(true);
    try {
      const res = await fetchHistory();
      if (res.success) {
        setHistory(res.data);
      }
    } catch (err: any) {
      console.error('Failed to load query history:', err);
    } finally {
      setIsHistoryLoading(false);
    }
  };

  useEffect(() => {
    loadHistory(true);
  }, []);

  const handleQuerySubmit = async (question: string) => {
    setIsLoading(true);
    setError(null);
    setActiveResult(null);
    setSelectedHistoryId(undefined);

    try {
      const result = await submitQuery(question);
      setActiveResult(result);
      
      // Reload history to show the new query
      await loadHistory();
      
      // Select the top item in history since it matches this query
      if (history.length > 0) {
        setSelectedHistoryId(history[0].id);
      }
    } catch (err: any) {
      setError(err.message || 'An error occurred while communicating with the server.');
      await loadHistory();
    } finally {
      setIsLoading(false);
    }
  };

  const handleSelectHistoryItem = (item: HistoryItem) => {
    setSelectedHistoryId(item.id);
    setError(null);
    
    // Construct QueryResponse statically from logs so it doesn't auto-run
    setActiveResult({
      question: item.question,
      sql: item.generated_sql,
      guardrails: {
        allowed: item.passed_guardrails,
        reason: item.execution_status === 'blocked' ? item.error_message : null,
      },
      schema_validation: item.execution_status === 'failed' && item.error_message?.includes('Schema') ? {
        valid: false,
        reason: item.error_message,
      } : {
        valid: item.execution_status === 'success' || item.execution_status === 'failed',
        reason: null,
      },
      execution: {
        success: item.execution_status === 'success',
        error: item.execution_status === 'failed' ? item.error_message : null,
        time_ms: item.execution_time_ms || 0,
        results: [], // History logs do not persist output cells
      },
      confidence: item.confidence_score,
      explanation: 'Restored from history. Click "Execute Custom SQL" to run and fetch results.',
    });
  };

  const handleSqlSubmit = async (editedSql: string) => {
    if (!activeResult) return;
    setIsLoading(true);
    setError(null);

    try {
      const result = await submitQuery(activeResult.question, editedSql);
      setActiveResult(result);
      await loadHistory();
    } catch (err: any) {
      setError(err.message || 'An error occurred while re-running custom SQL.');
      await loadHistory();
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      
      <div className="flex-1 flex overflow-hidden">
        <HistorySidebar
          history={history}
          isLoading={isHistoryLoading}
          onRefresh={() => loadHistory(true)}
          onSelectItem={handleSelectHistoryItem}
          selectedId={selectedHistoryId}
        />

        <main className="flex-1 overflow-y-auto p-6 space-y-6">
          {/* Query Input Section */}
          <Card title="Ask Your Database" className="glow-active">
            <QueryInput onSubmit={handleQuerySubmit} isLoading={isLoading} />
          </Card>

          {/* Grid Layout: Results Workspace & Schema Explorer */}
          <div className="grid grid-cols-1 xl:grid-cols-4 gap-6 items-start">
            <div className="xl:col-span-3 space-y-6">
              {/* Loading Indicator */}
              {isLoading && (
                <Card className="flex items-center justify-center py-12 bg-slate-900/10">
                  <Loader message="Processing query workspace transaction..." />
                </Card>
              )}

              {/* Error Banner */}
              {error && (
                <div className="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-450 text-sm">
                  <span className="font-bold">Error:</span> {error}
                </div>
              )}

              {/* Query Result Workspace */}
              {activeResult && !isLoading && (
                <div className="space-y-6 animate-fadeIn">
                  {/* Question Banner & Explanation */}
                  <Card className="bg-slate-900/10">
                    <div className="space-y-3">
                      <div className="flex items-center space-x-2 text-xs text-slate-500">
                        <span className="font-mono">QUESTION</span>
                      </div>
                      <h2 className="text-xl font-semibold text-white leading-snug">
                        &ldquo;{activeResult.question}&rdquo;
                      </h2>
                      {activeResult.explanation && (
                        <p className="text-sm text-slate-350 leading-relaxed pt-2 border-t border-slate-800/40">
                          {activeResult.explanation}
                        </p>
                      )}
                    </div>
                  </Card>

                  {/* Guardrails, Schema Validation, and Confidence Layout */}
                  <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <GuardrailCard info={activeResult.guardrails} />
                    
                    {activeResult.schema_validation && (
                      <div className={`p-4 rounded-xl border flex items-start space-x-3 transition-all duration-200 ${
                        activeResult.schema_validation.valid
                          ? 'bg-blue-500/10 border-blue-500/20 text-blue-405'
                          : 'bg-rose-500/10 border-rose-500/20 text-rose-450'
                      }`}>
                        <div className="mt-0.5 shrink-0">
                          {activeResult.schema_validation.valid ? (
                            <svg className="w-5 h-5 text-blue-450" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                          ) : (
                            <svg className="w-5 h-5 text-rose-450" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                          )}
                        </div>
                        <div className="space-y-1">
                          <h4 className="text-xs font-bold uppercase tracking-wider">
                            {activeResult.schema_validation.valid ? 'Schema Verified' : 'Schema Failed'}
                          </h4>
                          <p className="text-xs text-slate-350 leading-relaxed">
                            {activeResult.schema_validation.valid
                              ? 'All referenced database objects exist.'
                              : activeResult.schema_validation.reason || 'Query references unknown columns or tables.'}
                          </p>
                        </div>
                      </div>
                    )}

                    {activeResult.guardrails.allowed && activeResult.confidence !== null && (
                      <ConfidenceMeter score={activeResult.confidence} />
                    )}
                  </div>

                  {/* SQL Code Workspace Editor */}
                  {activeResult.sql && (
                    <SqlEditor
                      originalSql={activeResult.sql}
                      onRun={handleSqlSubmit}
                      isLoading={isLoading}
                    />
                  )}

                  {/* Dynamic Chart Visualizer */}
                  {activeResult.guardrails.allowed && activeResult.schema_validation?.valid && activeResult.execution.success && activeResult.execution.results.length > 0 && (
                    <ChartResult results={activeResult.execution.results} />
                  )}

                  {/* Data Table */}
                  {activeResult.guardrails.allowed && activeResult.schema_validation?.valid && (
                    <Card title="Query Results">
                      <ResultTable execution={activeResult.execution} />
                    </Card>
                  )}
                </div>
              )}

              {/* Empty Workspace State */}
              {!activeResult && !isLoading && !error && (
                <div className="flex flex-col items-center justify-center py-20 text-center space-y-4">
                  <div className="w-16 h-16 rounded-full bg-slate-900/40 border border-slate-800/50 flex items-center justify-center text-slate-500">
                    <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                    </svg>
                  </div>
                  <div className="space-y-1 max-w-sm">
                    <h3 className="text-sm font-semibold text-slate-300">No Query Executed</h3>
                    <p className="text-xs text-slate-550 leading-relaxed">
                      Type a natural language question in the box above or select one of the Quick Start prompts to query the seeded relational business data.
                    </p>
                  </div>
                </div>
              )}
            </div>

            {/* Sidebar Database Schema Explorer */}
            <div className="xl:col-span-1">
              <SchemaExplorer />
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}
