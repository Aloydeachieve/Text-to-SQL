'use client';

import React from 'react';
import { SemanticValidationInfo, RelevantSchemaInfo, ExecutionInfo } from '../../services/api';
import { SemanticWarnings } from '../semantic/SemanticWarnings';
import { RequestIdBadge } from '../system/RequestIdBadge';

interface QueryIntentCardProps {
  info?: SemanticValidationInfo | null;
  question: string;
  isCustomSql?: boolean;
  isAmbiguous?: boolean;
  clarification?: string | null;
  suggestions?: string[];
  relevantSchema?: RelevantSchemaInfo | null;
  onSelectSuggestion?: (suggestion: string) => void;
  requestId?: string | null;
  riskLevel?: 'low' | 'medium' | 'high';
  riskReasons?: string[];
  execution?: ExecutionInfo | null;
}

export function QueryIntentCard({
  info,
  question,
  isCustomSql = false,
  isAmbiguous = false,
  clarification = null,
  suggestions = [],
  relevantSchema = null,
  onSelectSuggestion,
  requestId = null,
  riskLevel,
  riskReasons = [],
  execution = null,
}: QueryIntentCardProps) {
  // 1. Ambiguous Query Clarification State
  if (isAmbiguous) {
    return (
      <div className="p-5 rounded-2xl border border-amber-500/30 bg-amber-950/20 text-amber-200 space-y-4 animate-fadeIn">
        <div className="flex items-start justify-between">
          <div className="flex items-center space-x-2.5">
            <span className="p-1.5 rounded-lg bg-amber-500/20 text-amber-400">
              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </span>
            <div>
              <h3 className="text-sm font-bold tracking-wide uppercase text-amber-300">
                Question Requires Clarification
              </h3>
              <p className="text-xs text-amber-400/80">
                Multiple business interpretations detected for this question
              </p>
            </div>
          </div>
          <span className="px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/30">
            Clarification Needed
          </span>
        </div>

        <div className="space-y-3 bg-slate-950/50 p-4 rounded-xl border border-amber-500/15 text-xs">
          <div>
            <span className="font-semibold text-slate-400 block mb-0.5">Your Question:</span>
            <p className="text-slate-200 italic font-medium">&ldquo;{question}&rdquo;</p>
          </div>

          <div>
            <span className="font-semibold text-slate-400 block mb-0.5">Ambiguity Details:</span>
            <p className="text-amber-200 leading-relaxed font-medium">
              {clarification || 'This question is underspecified. Please choose one of the suggested interpretations below to execute an accurate query.'}
            </p>
          </div>
        </div>

        {/* Actionable Suggestions */}
        {suggestions.length > 0 && (
          <div className="space-y-2 pt-1">
            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">
              Suggested Clarifications (Click to execute):
            </span>
            <div className="flex flex-wrap gap-2">
              {suggestions.map((suggestion, idx) => (
                <button
                  key={idx}
                  onClick={() => onSelectSuggestion?.(suggestion)}
                  className="px-3 py-1.5 rounded-lg bg-indigo-950/60 hover:bg-indigo-900/80 text-indigo-200 border border-indigo-500/30 hover:border-indigo-400 text-xs font-medium transition-all shadow-sm flex items-center space-x-1.5 group text-left"
                >
                  <span>&ldquo;{suggestion}&rdquo;</span>
                  <span className="text-indigo-400 group-hover:translate-x-0.5 transition-transform">→</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
    );
  }

  // 2. Custom SQL without semantic validation details
  if (isCustomSql && !info) {
    return (
      <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/30 text-slate-400 space-y-2">
        <div className="flex items-center space-x-2">
          <span className="text-xs font-bold uppercase tracking-wider text-slate-300">
            Manual Query Execution
          </span>
          <span className="px-2 py-0.5 text-[10px] font-mono rounded bg-slate-800 text-slate-400">
            Intent Check Bypassed
          </span>
        </div>
        <p className="text-xs text-slate-400 leading-relaxed">
          Custom SQL bypasses natural-language intent verification. Static SQL security guardrails and schema validation remain actively enforced.
        </p>
      </div>
    );
  }

  if (!info) {
    return null;
  }

  const {
    valid,
    score,
    reason,
    interpretation,
    tables = [],
    joins = [],
    grain,
    filters = [],
    aggregations = [],
    multiplication_risk,
  } = info;

  const percentage = Math.round((score ?? 1) * 100);
  const effectiveRiskLevel = riskLevel || info.risk_level;
  const effectiveRiskReasons = riskReasons.length > 0 ? riskReasons : (info.risk_reasons || []);

  return (
    <div
      className={`p-5 rounded-2xl border space-y-4 animate-fadeIn ${
        !valid
          ? 'border-amber-500/30 bg-amber-950/20 text-amber-200'
          : 'border-indigo-500/20 bg-indigo-950/15 text-slate-200'
      }`}
    >
      {/* Header Bar */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-800/80 pb-3">
        <div className="flex items-center space-x-2.5">
          <span
            className={`p-1.5 rounded-lg ${
              !valid
                ? 'bg-amber-500/20 text-amber-400'
                : 'bg-indigo-500/20 text-indigo-400'
            }`}
          >
            {!valid ? (
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            ) : (
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            )}
          </span>
          <div>
            <h3 className="text-xs font-bold uppercase tracking-wider text-white">
              {!valid
                ? 'Query Intent Mismatch'
                : isCustomSql
                ? 'Query Structure & Interpretation'
                : 'Query Intent Verification'}
            </h3>
            <span className="text-[11px] text-slate-400">
              {!valid
                ? 'The generated SQL does not match your intended question'
                : isCustomSql
                ? 'Syntactic structure, join cardinality, and grain extracted'
                : 'Semantic alignment confirmed'}
            </span>
          </div>
        </div>

        <div className="flex items-center space-x-2 flex-wrap gap-y-1">
          {relevantSchema?.is_subset && (
            <span
              className="px-2 py-0.5 rounded-full text-[10px] font-mono font-semibold bg-blue-500/10 text-blue-300 border border-blue-500/20"
              title={`Directly matched: ${relevantSchema.matched_tables.join(', ') || 'none'}`}
            >
              ⚡ Relevant Subset ({relevantSchema.included_tables}/{relevantSchema.total_tables} tbls)
            </span>
          )}

          {effectiveRiskLevel && (
            <span
              className={`px-2 py-0.5 rounded-full text-[10px] font-semibold border flex items-center gap-1.5 ${
                effectiveRiskLevel === 'high'
                  ? 'bg-rose-950/50 border-rose-500/40 text-rose-300'
                  : effectiveRiskLevel === 'medium'
                  ? 'bg-amber-950/50 border-amber-500/40 text-amber-300'
                  : 'bg-emerald-950/50 border-emerald-500/30 text-emerald-300'
              }`}
              title={effectiveRiskReasons.length > 0 ? effectiveRiskReasons.join('; ') : `${effectiveRiskLevel.toUpperCase()} Complexity Risk`}
            >
              <span className={`w-1.5 h-1.5 rounded-full ${
                effectiveRiskLevel === 'high' ? 'bg-rose-400 animate-pulse' : effectiveRiskLevel === 'medium' ? 'bg-amber-400' : 'bg-emerald-400'
              }`} />
              {effectiveRiskLevel.toUpperCase()} RISK
            </span>
          )}

          <RequestIdBadge requestId={requestId} compact />

          {!valid ? (
            <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold tracking-wide bg-rose-500/20 text-rose-300 border border-rose-500/30">
              Execution Blocked
            </span>
          ) : isCustomSql ? (
            <span className="px-2 py-0.5 rounded-md text-[10px] font-mono text-slate-300 bg-slate-800 border border-slate-700">
              Manual Execution
            </span>
          ) : (
            <>
              <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold tracking-wide bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                ✓ Intent Matches Query
              </span>
              <span className="px-2 py-0.5 rounded-md text-[11px] font-mono text-indigo-300 bg-indigo-900/40 border border-indigo-500/20">
                {percentage}% match
              </span>
            </>
          )}
        </div>
      </div>

      {/* Truncation Notice Banner */}
      {execution?.truncated && (
        <div className="flex items-center gap-2.5 p-3 rounded-xl bg-amber-950/30 border border-amber-500/30 text-amber-200 text-xs">
          <span className="font-bold text-amber-400 uppercase tracking-wider text-[10px] py-0.5 px-2 rounded bg-amber-500/20 border border-amber-500/30 shrink-0">
            Truncated
          </span>
          <p className="leading-relaxed">
            Query returned more than <strong>{execution.limit ?? 1000}</strong> rows. Result set is capped to safeguard browser memory and response time.
          </p>
        </div>
      )}

      {/* Query Complexity Advisory Banner */}
      {effectiveRiskReasons.length > 0 && effectiveRiskLevel !== 'low' && (
        <div className="p-3 rounded-xl bg-slate-950/50 border border-amber-500/30 text-xs space-y-1.5">
          <span className="text-[10px] font-bold uppercase tracking-wider text-amber-400 flex items-center gap-1.5">
            ⚠ Complexity Advisory ({effectiveRiskReasons.length}):
          </span>
          <ul className="list-disc list-inside space-y-1 text-slate-300 text-[11px]">
            {effectiveRiskReasons.map((reason, idx) => (
              <li key={idx} className="leading-relaxed">{reason}</li>
            ))}
          </ul>
        </div>
      )}

      {/* Question & AI Interpretation Context */}
      <div className="bg-slate-950/40 p-3.5 rounded-xl border border-slate-800/80 space-y-2 text-xs">
        {question && !isCustomSql && (
          <div>
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">
              Your Question:
            </span>
            <p className="text-slate-200 italic font-medium">&ldquo;{question}&rdquo;</p>
          </div>
        )}

        <div>
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-0.5">
            {isCustomSql ? 'Query Summary:' : 'AI Understood Your Question As:'}
          </span>
          <p className="text-xs text-indigo-200 font-medium leading-relaxed">
            &ldquo;{interpretation}&rdquo;
          </p>
        </div>

        {reason && (
          <div>
            <span className="text-[10px] font-bold uppercase tracking-wider text-rose-400 block mb-0.5">
              Why this was blocked:
            </span>
            <p className="text-rose-300 leading-relaxed font-medium">{reason}</p>
          </div>
        )}
      </div>

      {/* Query Interpretation Section (Requested Layout) */}
      <div className="space-y-3 pt-1">
        <div className="flex items-center justify-between border-b border-slate-800/60 pb-1.5">
          <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-300 flex items-center space-x-1.5">
            <span>Query interpretation</span>
          </h4>
          {grain && (
            <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-indigo-950 text-indigo-300 border border-indigo-800/40">
              Grain: <strong className="text-white">{grain}</strong>
            </span>
          )}
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
          {/* 1. Tables used */}
          <div className="bg-slate-900/50 p-3 rounded-xl border border-slate-800 space-y-1.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
              Tables used:
            </span>
            <div className="flex flex-col space-y-1">
              {tables.length > 0 ? (
                tables.map((tbl) => (
                  <span
                    key={tbl}
                    className="font-mono text-[11px] text-blue-300 font-semibold px-2 py-0.5 rounded bg-blue-950/40 border border-blue-800/30 w-fit"
                  >
                    {tbl}
                  </span>
                ))
              ) : (
                <span className="text-slate-500 italic text-[11px]">None</span>
              )}
            </div>
          </div>

          {/* 2. Join */}
          <div className="bg-slate-900/50 p-3 rounded-xl border border-slate-800 space-y-1.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
              Join:
            </span>
            <div className="flex flex-col space-y-1">
              {joins && joins.length > 0 ? (
                joins.map((j, idx) => (
                  <span
                    key={idx}
                    className="font-mono text-[11px] text-indigo-300 px-2 py-0.5 rounded bg-indigo-950/50 border border-indigo-800/40 w-fit"
                  >
                    {j}
                  </span>
                ))
              ) : (
                <span className="text-slate-500 italic text-[11px]">No joins (Single table)</span>
              )}
            </div>
          </div>

          {/* 3. Filters */}
          <div className="bg-slate-900/50 p-3 rounded-xl border border-slate-800 space-y-1.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
              Filters:
            </span>
            <div className="flex flex-col space-y-1">
              {filters.length > 0 ? (
                filters.map((f, idx) => (
                  <span
                    key={idx}
                    className="font-mono text-[11px] text-slate-300 px-2 py-0.5 rounded bg-slate-950 border border-slate-800 w-fit truncate max-w-full"
                    title={f}
                  >
                    {f}
                  </span>
                ))
              ) : (
                <span className="text-slate-500 italic text-[11px]">None (Unfiltered)</span>
              )}
            </div>
          </div>

          {/* 4. Aggregation */}
          <div className="bg-slate-900/50 p-3 rounded-xl border border-slate-800 space-y-1.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
              Aggregation:
            </span>
            <div className="flex flex-col space-y-1">
              {aggregations && aggregations.length > 0 ? (
                aggregations.map((agg, idx) => (
                  <span
                    key={idx}
                    className="font-mono text-[11px] text-amber-300 font-semibold px-2 py-0.5 rounded bg-amber-950/40 border border-amber-800/40 w-fit"
                  >
                    {agg}
                  </span>
                ))
              ) : (
                <span className="text-slate-500 italic text-[11px]">None (Row-level detail)</span>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Phase 9: Business Semantic Layer Intelligence */}
      {(info.metric || info.semantic_confidence || info.source_of_truth !== undefined || (info.required_filters && info.required_filters.length > 0)) && (
        <div className="p-4 rounded-xl border border-emerald-500/30 bg-emerald-950/20 text-emerald-200 space-y-3">
          <div className="flex items-center justify-between border-b border-emerald-500/20 pb-2 flex-wrap gap-2">
            <div className="flex items-center space-x-2">
              <span className="text-base">🧠</span>
              <span className="text-xs font-bold uppercase tracking-wider text-emerald-300">
                Business Semantics & Source-of-Truth
              </span>
            </div>

            <div className="flex items-center space-x-2">
              {info.semantic_confidence && (
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded-full border uppercase tracking-wider ${
                    info.semantic_confidence === 'HIGH'
                      ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40'
                      : info.semantic_confidence === 'MEDIUM'
                      ? 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                      : 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                  }`}
                >
                  {info.semantic_confidence} Confidence
                </span>
              )}

              {info.source_of_truth ? (
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">
                  ✓ Configured Company Source
                </span>
              ) : info.metric ? (
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">
                  ⚠ Non-canonical Source
                </span>
              ) : null}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 text-xs">
            {info.metric && (
              <div className="bg-slate-950/60 p-2.5 rounded-lg border border-emerald-500/20">
                <span className="text-[10px] font-bold uppercase text-slate-400 block mb-0.5">
                  Business Metric:
                </span>
                <span className="font-bold text-emerald-300 text-xs">
                  {info.metric}
                </span>
              </div>
            )}

            {info.metric_source && (
              <div className="bg-slate-950/60 p-2.5 rounded-lg border border-emerald-500/20">
                <span className="text-[10px] font-bold uppercase text-slate-400 block mb-0.5">
                  Canonical Source:
                </span>
                <span className="font-mono text-emerald-300 text-xs font-semibold">
                  {info.metric_source}.{info.metric_column || '*'}
                </span>
              </div>
            )}

            {info.aggregation && (
              <div className="bg-slate-950/60 p-2.5 rounded-lg border border-emerald-500/20">
                <span className="text-[10px] font-bold uppercase text-slate-400 block mb-0.5">
                  Required Aggregation:
                </span>
                <span className="font-mono text-indigo-300 text-xs font-semibold">
                  {info.aggregation}()
                </span>
              </div>
            )}

            {info.required_filters && info.required_filters.length > 0 && (
              <div className="bg-slate-950/60 p-2.5 rounded-lg border border-emerald-500/20">
                <span className="text-[10px] font-bold uppercase text-slate-400 block mb-0.5">
                  Required Filter:
                </span>
                <span className="font-mono text-amber-300 text-[11px] truncate block" title={info.required_filters.join(' AND ')}>
                  {info.required_filters.join(' AND ')}
                </span>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Structured Semantic Warnings */}
      {info.semantic_warnings && info.semantic_warnings.length > 0 && (
        <div className="space-y-1.5 pt-1">
          <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
            Semantic Intelligence Alerts:
          </span>
          <SemanticWarnings warnings={info.semantic_warnings} />
        </div>
      )}

      {/* ⚠ Potential multiplication risk alert callout */}
      {multiplication_risk?.detected && (
        <div className="p-4 rounded-xl border border-amber-500/40 bg-amber-950/30 text-amber-200 space-y-2 animate-fadeIn">
          <div className="flex items-center space-x-2">
            <span className="text-base text-amber-400">⚠</span>
            <span className="text-xs font-bold uppercase tracking-wider text-amber-300">
              Potential multiplication risk:
            </span>
          </div>

          <div className="pl-6 space-y-1">
            <p className="text-xs font-semibold text-white">
              {multiplication_risk.warning}
            </p>
            {multiplication_risk.details && (
              <p className="text-[11px] text-amber-300/90 leading-relaxed">
                {multiplication_risk.details}
              </p>
            )}
            {multiplication_risk.recommendation && (
              <p className="text-[11px] text-slate-300 pt-1 font-mono">
                <span className="text-amber-400 font-bold">Recommendation:</span>{' '}
                {multiplication_risk.recommendation}
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

export default QueryIntentCard;
