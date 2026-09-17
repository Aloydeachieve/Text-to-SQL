'use client';

import React, { useState, useEffect, useCallback } from 'react';
import {
  AuthUser,
  SemanticMetric,
  SemanticTerm,
  TableClassification,
  fetchSemanticMetrics,
  createSemanticMetric,
  updateSemanticMetric,
  deleteSemanticMetric,
  fetchSemanticTerms,
  createSemanticTerm,
  updateSemanticTerm,
  deleteSemanticTerm,
  fetchTableClassifications,
  saveTableClassification,
  deleteTableClassification,
  CreateMetricParams,
  UpdateMetricParams,
  CreateTermParams,
  UpdateTermParams,
  SaveClassificationParams,
} from '../../services/api';
import { MetricList } from './MetricList';
import { MetricFormModal } from './MetricFormModal';
import { BusinessTermList } from './BusinessTermList';
import { BusinessTermModal } from './BusinessTermModal';
import { TableClassificationList } from './TableClassificationList';
import { TableClassificationModal } from './TableClassificationModal';
import { LineageGraphView } from './LineageGraphView';

interface SemanticManagementProps {
  currentUser: AuthUser | null;
}

export function SemanticManagement({ currentUser }: SemanticManagementProps) {
  const [activeTab, setActiveTab] = useState<'metrics' | 'terms' | 'classifications' | 'lineage'>('metrics');

  // Metrics state
  const [metrics, setMetrics] = useState<SemanticMetric[]>([]);
  const [isMetricsLoading, setIsMetricsLoading] = useState(true);
  const [metricSearch, setMetricSearch] = useState('');
  const [activeOnly, setActiveOnly] = useState(false);
  const [isMetricModalOpen, setIsMetricModalOpen] = useState(false);
  const [editingMetric, setEditingMetric] = useState<SemanticMetric | null>(null);

  // Terms state
  const [terms, setTerms] = useState<SemanticTerm[]>([]);
  const [isTermsLoading, setIsTermsLoading] = useState(false);
  const [isTermModalOpen, setIsTermModalOpen] = useState(false);
  const [editingTerm, setEditingTerm] = useState<SemanticTerm | null>(null);

  // Table Classifications state
  const [classifications, setClassifications] = useState<TableClassification[]>([]);
  const [isClassificationsLoading, setIsClassificationsLoading] = useState(false);
  const [isClassificationModalOpen, setIsClassificationModalOpen] = useState(false);
  const [editingClassification, setEditingClassification] = useState<TableClassification | null>(null);

  // Lineage selected metric
  const [selectedLineageMetricId, setSelectedLineageMetricId] = useState<number | null>(null);

  // Toast notifications
  const [notification, setNotification] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const isAdmin = currentUser?.role === 'admin';

  const showToast = useCallback((type: 'success' | 'error', message: string) => {
    setNotification({ type, message });
    setTimeout(() => setNotification(null), 4000);
  }, []);

  // Load Metrics
  const loadMetrics = useCallback(async () => {
    setIsMetricsLoading(true);
    try {
      const data = await fetchSemanticMetrics(metricSearch, activeOnly);
      setMetrics(data);
    } catch (err: unknown) {
      showToast('error', err instanceof Error ? err.message : 'Failed to load metrics.');
    } finally {
      setIsMetricsLoading(false);
    }
  }, [metricSearch, activeOnly, showToast]);

  // Load Terms
  const loadTerms = useCallback(async () => {
    setIsTermsLoading(true);
    try {
      const data = await fetchSemanticTerms();
      setTerms(data);
    } catch (err: unknown) {
      showToast('error', err instanceof Error ? err.message : 'Failed to load terms.');
    } finally {
      setIsTermsLoading(false);
    }
  }, [showToast]);

  // Load Classifications
  const loadClassifications = useCallback(async () => {
    setIsClassificationsLoading(true);
    try {
      const data = await fetchTableClassifications();
      setClassifications(data);
    } catch (err: unknown) {
      showToast('error', err instanceof Error ? err.message : 'Failed to load table classifications.');
    } finally {
      setIsClassificationsLoading(false);
    }
  }, [showToast]);

  // Initial load
  useEffect(() => {
    let ignore = false;
    fetchSemanticMetrics(metricSearch, activeOnly)
      .then((data) => {
        if (!ignore) {
          setMetrics(data);
          setIsMetricsLoading(false);
        }
      })
      .catch((err: unknown) => {
        if (!ignore) {
          showToast('error', err instanceof Error ? err.message : 'Failed to load metrics.');
          setIsMetricsLoading(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [metricSearch, activeOnly, showToast]);

  const handleTabChange = (tab: 'metrics' | 'terms' | 'classifications' | 'lineage') => {
    setActiveTab(tab);
    if (tab === 'metrics') {
      loadMetrics();
    } else if (tab === 'terms') {
      loadTerms();
    } else if (tab === 'classifications') {
      loadClassifications();
    } else if (tab === 'lineage') {
      loadMetrics();
    }
  };

  // Metric Handlers
  const handleSaveMetric = async (params: CreateMetricParams | UpdateMetricParams) => {
    if (editingMetric) {
      const updated = await updateSemanticMetric(editingMetric.id, params as UpdateMetricParams);
      setMetrics((prev) => prev.map((m) => (m.id === updated.id ? updated : m)));
      showToast('success', `Metric "${updated.name}" updated.`);
    } else {
      const created = await createSemanticMetric(params as CreateMetricParams);
      setMetrics((prev) => [created, ...prev]);
      showToast('success', `Metric "${created.name}" created.`);
    }
  };

  const handleDeleteMetric = async (id: number) => {
    await deleteSemanticMetric(id);
    setMetrics((prev) => prev.filter((m) => m.id !== id));
    showToast('success', 'Metric deleted.');
  };

  // Term Handlers
  const handleSaveTerm = async (params: CreateTermParams | UpdateTermParams) => {
    if (editingTerm) {
      const updated = await updateSemanticTerm(editingTerm.id, params as UpdateTermParams);
      setTerms((prev) => prev.map((t) => (t.id === updated.id ? updated : t)));
      showToast('success', `Terminology mapping for "${updated.term}" updated.`);
    } else {
      const created = await createSemanticTerm(params as CreateTermParams);
      setTerms((prev) => [created, ...prev]);
      showToast('success', `Terminology mapping for "${created.term}" created.`);
    }
  };

  const handleDeleteTerm = async (id: number) => {
    await deleteSemanticTerm(id);
    setTerms((prev) => prev.filter((t) => t.id !== id));
    showToast('success', 'Terminology mapping removed.');
  };

  // Classification Handlers
  const handleSaveClassification = async (params: SaveClassificationParams) => {
    const saved = await saveTableClassification(params);
    setClassifications((prev) => {
      const exists = prev.some((c) => c.id === saved.id);
      return exists ? prev.map((c) => (c.id === saved.id ? saved : c)) : [saved, ...prev];
    });
    showToast('success', `Classification for table "${saved.table_name}" saved.`);
  };

  const handleDeleteClassification = async (id: number) => {
    await deleteTableClassification(id);
    setClassifications((prev) => prev.filter((c) => c.id !== id));
    showToast('success', 'Table classification removed.');
  };

  return (
    <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 py-6 animate-fadeIn">
      {/* Toast Notification */}
      {notification && (
        <div
          className={`fixed bottom-6 right-6 z-50 p-4 rounded-xl shadow-xl text-xs font-semibold flex items-center space-x-2 animate-fadeIn border ${
            notification.type === 'success'
              ? 'bg-emerald-950 border-emerald-500/40 text-emerald-200'
              : 'bg-rose-950 border-rose-500/40 text-rose-200'
          }`}
        >
          <span>{notification.type === 'success' ? '✓' : '⚠'}</span>
          <span>{notification.message}</span>
        </div>
      )}

      {/* Main Title & Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
          <div className="flex items-center space-x-3">
            <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-600 flex items-center justify-center font-bold text-white shadow-lg shadow-emerald-500/20">
              🧠
            </div>
            <div>
              <h1 className="text-xl font-bold tracking-tight text-white flex items-center space-x-2">
                <span>Business Semantic Model & Lineage</span>
                <span className="text-[11px] font-mono px-2 py-0.5 rounded-full bg-emerald-950/80 text-emerald-300 border border-emerald-800/40">
                  Phase 9
                </span>
              </h1>
              <p className="text-xs text-slate-400 mt-0.5">
                Define source-of-truth metrics, business terminology, and table classifications for accurate Text-to-SQL
              </p>
            </div>
          </div>
        </div>

        {/* Role Capability Banner */}
        <div className="text-right">
          {isAdmin ? (
            <span className="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
              Admin Access: Full Edit Privileges
            </span>
          ) : (
            <span className="px-3 py-1 rounded-full text-xs font-semibold bg-slate-800 text-slate-400 border border-slate-700">
              Read-Only: Viewing Company Semantic Metadata
            </span>
          )}
        </div>
      </div>

      {/* Navigation Sub-Tabs */}
      <div className="flex items-center space-x-2 border-b border-slate-800 pb-2">
        <button
          onClick={() => handleTabChange('metrics')}
          className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'metrics'
              ? 'bg-emerald-600 text-white shadow-sm shadow-emerald-600/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-850'
          }`}
        >
          <span>📊</span>
          <span>Business Metrics</span>
          <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-900/80 text-slate-300 ml-1">
            {metrics.length}
          </span>
        </button>

        <button
          onClick={() => handleTabChange('terms')}
          className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'terms'
              ? 'bg-purple-600 text-white shadow-sm shadow-purple-600/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-850'
          }`}
        >
          <span>📖</span>
          <span>Business Terms</span>
          <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-900/80 text-slate-300 ml-1">
            {terms.length}
          </span>
        </button>

        <button
          onClick={() => handleTabChange('classifications')}
          className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'classifications'
              ? 'bg-blue-600 text-white shadow-sm shadow-blue-600/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-850'
          }`}
        >
          <span>🏷️</span>
          <span>Table Classifications</span>
          <span className="text-[10px] px-1.5 py-0.2 rounded-full bg-slate-900/80 text-slate-300 ml-1">
            {classifications.length}
          </span>
        </button>

        <button
          onClick={() => handleTabChange('lineage')}
          className={`flex items-center space-x-2 px-4 py-2 rounded-xl text-xs font-semibold transition-all ${
            activeTab === 'lineage'
              ? 'bg-teal-600 text-white shadow-sm shadow-teal-600/20'
              : 'text-slate-400 hover:text-white hover:bg-slate-850'
          }`}
        >
          <span>🕸️</span>
          <span>Data Lineage</span>
        </button>
      </div>

      {/* Tab Panels */}
      {activeTab === 'metrics' && (
        <MetricList
          metrics={metrics}
          isAdmin={isAdmin}
          isLoading={isMetricsLoading}
          search={metricSearch}
          onSearchChange={setMetricSearch}
          activeOnly={activeOnly}
          onActiveOnlyToggle={() => setActiveOnly(!activeOnly)}
          onNewMetric={() => {
            setEditingMetric(null);
            setIsMetricModalOpen(true);
          }}
          onEditMetric={(m) => {
            setEditingMetric(m);
            setIsMetricModalOpen(true);
          }}
          onDeleteMetric={handleDeleteMetric}
          onSelectMetricForLineage={(id) => {
            setSelectedLineageMetricId(id);
            setActiveTab('lineage');
          }}
        />
      )}

      {activeTab === 'terms' && (
        <BusinessTermList
          terms={terms}
          isAdmin={isAdmin}
          isLoading={isTermsLoading}
          onNewTerm={() => {
            setEditingTerm(null);
            setIsTermModalOpen(true);
          }}
          onEditTerm={(t) => {
            setEditingTerm(t);
            setIsTermModalOpen(true);
          }}
          onDeleteTerm={handleDeleteTerm}
        />
      )}

      {activeTab === 'classifications' && (
        <TableClassificationList
          classifications={classifications}
          isAdmin={isAdmin}
          isLoading={isClassificationsLoading}
          onNewClassification={() => {
            setEditingClassification(null);
            setIsClassificationModalOpen(true);
          }}
          onEditClassification={(c) => {
            setEditingClassification(c);
            setIsClassificationModalOpen(true);
          }}
          onDeleteClassification={handleDeleteClassification}
        />
      )}

      {activeTab === 'lineage' && (
        <LineageGraphView
          metrics={metrics}
          selectedMetricId={selectedLineageMetricId}
          onSelectMetric={setSelectedLineageMetricId}
        />
      )}

      {/* Modals */}
      <MetricFormModal
        isOpen={isMetricModalOpen}
        metric={editingMetric}
        onClose={() => setIsMetricModalOpen(false)}
        onSave={handleSaveMetric}
      />

      <BusinessTermModal
        isOpen={isTermModalOpen}
        term={editingTerm}
        metrics={metrics}
        onClose={() => setIsTermModalOpen(false)}
        onSave={handleSaveTerm}
      />

      <TableClassificationModal
        isOpen={isClassificationModalOpen}
        classification={editingClassification}
        onClose={() => setIsClassificationModalOpen(false)}
        onSave={handleSaveClassification}
      />
    </div>
  );
}

export default SemanticManagement;
