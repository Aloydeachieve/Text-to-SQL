import React, { useState, useEffect, useCallback } from 'react';
import {
  AuthUser,
  DatabaseConnectionItem,
  DatabaseConnectionHealth,
  SchemaDetails,
  loginTenant,
  registerTenant,
  logoutTenant,
  fetchDatabaseConnections,
  testDatabaseConnection,
  saveDatabaseConnection,
  deleteDatabaseConnection,
  fetchConnectionSchema,
  checkDatabaseConnectionHealth,
} from '../../services/api';

interface DatabaseConnectionModalProps {
  isOpen: boolean;
  onClose: () => void;
  currentUser: AuthUser | null;
  onUserChanged: (user: AuthUser | null) => void;
}

export function DatabaseConnectionModal({
  isOpen,
  onClose,
  currentUser,
  onUserChanged,
}: DatabaseConnectionModalProps) {
  // Auth state
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [authName, setAuthName] = useState('');
  const [authEmail, setAuthEmail] = useState('');
  const [authPassword, setAuthPassword] = useState('');
  const [authCompanyName, setAuthCompanyName] = useState('');
  const [authLoading, setAuthLoading] = useState(false);
  const [authError, setAuthError] = useState<string | null>(null);

  // Connection management state
  const [connections, setConnections] = useState<DatabaseConnectionItem[]>([]);
  const [isLoadingConnections, setIsLoadingConnections] = useState(false);
  const [healthResults, setHealthResults] = useState<Record<number, DatabaseConnectionHealth>>({});
  const [checkingHealthId, setCheckingHealthId] = useState<number | null>(null);
  const [selectedConnectionSchema, setSelectedConnectionSchema] = useState<{
    connection: DatabaseConnectionItem;
    schema: SchemaDetails;
  } | null>(null);
  const [schemaLoadingId, setSchemaLoadingId] = useState<number | null>(null);

  // New connection form state
  const [showAddForm, setShowAddForm] = useState(false);
  const [driver, setDriver] = useState<'mysql' | 'pgsql'>('mysql');
  const [connName, setConnName] = useState('');
  const [host, setHost] = useState('127.0.0.1');
  const [port, setPort] = useState(3306);
  const [database, setDatabase] = useState('');
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');

  // Probe testing / saving feedback
  const [isTesting, setIsTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);

  const loadConnections = useCallback(async () => {
    setIsLoadingConnections(true);
    setActionError(null);
    try {
      const data = await fetchDatabaseConnections();
      setConnections(data);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to load connections.';
      setActionError(msg);
    } finally {
      setIsLoadingConnections(false);
    }
  }, []);

  // Load connections asynchronously when modal opens and user is logged in
  useEffect(() => {
    if (!isOpen || !currentUser) return;
    let ignore = false;

    fetchDatabaseConnections()
      .then((data) => {
        if (!ignore) {
          setConnections(data);
        }
      })
      .catch((err: unknown) => {
        if (!ignore) {
          const msg = err instanceof Error ? err.message : 'Failed to load connections.';
          setActionError(msg);
        }
      });

    return () => {
      ignore = true;
    };
  }, [isOpen, currentUser]);

  const handleDriverChange = (newDriver: 'mysql' | 'pgsql') => {
    setDriver(newDriver);
    if (newDriver === 'mysql') {
      setPort(3306);
    } else {
      setPort(5432);
    }
    setTestResult(null);
  };

  const handleTestConnection = async () => {
    if (!host || !database || !username) {
      setTestResult({
        success: false,
        message: 'Host, Database, and Username are required.',
      });
      return;
    }

    setIsTesting(true);
    setTestResult(null);
    setActionError(null);

    try {
      const res = await testDatabaseConnection({
        driver,
        host,
        port: Number(port),
        database,
        username,
        password,
      });
      setTestResult(res);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Connection test probe failed.';
      setTestResult({ success: false, message: msg });
    } finally {
      setIsTesting(false);
    }
  };

  const handleSaveConnection = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!connName.trim()) {
      setActionError('Please provide a connection name.');
      return;
    }

    setIsSaving(true);
    setActionError(null);
    setActionSuccess(null);

    try {
      await saveDatabaseConnection({
        name: connName,
        driver,
        host,
        port: Number(port),
        database,
        username,
        password,
      });

      setActionSuccess(`Connection "${connName}" added successfully.`);
      setShowAddForm(false);
      setTestResult(null);
      // Reset sensitive fields
      setPassword('');
      setConnName('');
      setDatabase('');
      setUsername('');
      await loadConnections();
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to save connection.';
      setActionError(msg);
    } finally {
      setIsSaving(false);
    }
  };

  const handleDeleteConnection = async (id: number, name: string) => {
    if (!window.confirm(`Are you sure you want to remove connection "${name}"?`)) return;

    try {
      await deleteDatabaseConnection(id);
      if (selectedConnectionSchema?.connection.id === id) {
        setSelectedConnectionSchema(null);
      }
      await loadConnections();
      setActionSuccess(`Connection "${name}" deleted.`);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to delete connection.';
      setActionError(msg);
    }
  };

  const handleViewSchema = async (conn: DatabaseConnectionItem) => {
    setSchemaLoadingId(conn.id);
    setActionError(null);
    try {
      const schema = await fetchConnectionSchema(conn.id);
      setSelectedConnectionSchema({ connection: conn, schema });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Unable to introspect connection schema.';
      setActionError(msg);
    } finally {
      setSchemaLoadingId(null);
    }
  };

  const handleAuthSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthError(null);

    try {
      if (authMode === 'register') {
        const res = await registerTenant({
          name: authName,
          email: authEmail,
          password: authPassword,
          company_name: authCompanyName,
        });
        if (res.user) {
          onUserChanged(res.user);
        }
      } else {
        const res = await loginTenant({
          email: authEmail,
          password: authPassword,
        });
        if (res.user) {
          onUserChanged(res.user);
        }
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Authentication failed.';
      setAuthError(msg);
    } finally {
      setAuthLoading(false);
    }
  };

  const handleLogout = async () => {
    await logoutTenant();
    onUserChanged(null);
    setConnections([]);
    setSelectedConnectionSchema(null);
  };

  const handleCheckHealth = async (connId: number) => {
    setCheckingHealthId(connId);
    try {
      const health = await checkDatabaseConnectionHealth(connId);
      setHealthResults((prev) => ({ ...prev, [connId]: health }));
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Health check probe failed.';
      setHealthResults((prev) => ({
        ...prev,
        [connId]: {
          id: connId,
          name: '',
          driver: 'mysql',
          healthy: false,
          status: 'unhealthy',
          latency_ms: 0,
          message: msg,
          timestamp: new Date().toISOString(),
        },
      }));
    } finally {
      setCheckingHealthId(null);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm animate-fadeIn">
      <div className="bg-slate-900 border border-slate-800 w-full max-w-4xl rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        {/* Modal Header */}
        <div className="px-6 py-4 border-b border-slate-800/80 flex items-center justify-between bg-slate-950/40">
          <div className="flex items-center space-x-3">
            <div className="w-8 h-8 rounded-lg bg-blue-600/20 border border-blue-500/30 flex items-center justify-center text-blue-400">
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3m-16 5c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3" />
              </svg>
            </div>
            <div>
              <h2 className="text-base font-semibold text-white flex items-center gap-2">
                Multi-Tenant Database Connections
                {currentUser?.company && (
                  <span className="text-xs font-normal text-blue-300 bg-blue-950/60 border border-blue-500/30 px-2 py-0.5 rounded-full">
                    🏢 {currentUser.company.name}
                  </span>
                )}
              </h2>
              <p className="text-xs text-slate-400">
                Connect and introspect isolated MySQL and PostgreSQL customer databases.
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2">
            {currentUser && (
              <button
                onClick={handleLogout}
                className="text-xs text-slate-400 hover:text-red-400 px-3 py-1.5 rounded-lg border border-slate-800 hover:border-red-900/50 bg-slate-900 transition-colors"
              >
                Sign Out ({currentUser.name})
              </button>
            )}
            <button
              onClick={onClose}
              className="text-slate-400 hover:text-white p-1.5 rounded-lg hover:bg-slate-800 transition-colors"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto space-y-6 flex-1">
          {/* If NOT logged in: Show Auth Screen */}
          {!currentUser ? (
            <div className="max-w-md mx-auto py-4 space-y-6">
              <div className="text-center space-y-2">
                <h3 className="text-lg font-bold text-white">
                  {authMode === 'login' ? 'Company Account Login' : 'Register New Tenant Company'}
                </h3>
                <p className="text-xs text-slate-400">
                  Authentication is required to ensure database credentials and schema metadata remain strictly tenant-isolated.
                </p>
              </div>

              {/* Toggle Mode */}
              <div className="flex p-1 bg-slate-950/60 border border-slate-800 rounded-xl">
                <button
                  type="button"
                  onClick={() => { setAuthMode('login'); setAuthError(null); }}
                  className={`flex-1 py-1.5 text-xs font-medium rounded-lg transition-all ${
                    authMode === 'login'
                      ? 'bg-blue-600 text-white shadow-md'
                      : 'text-slate-400 hover:text-white'
                  }`}
                >
                  Sign In
                </button>
                <button
                  type="button"
                  onClick={() => { setAuthMode('register'); setAuthError(null); }}
                  className={`flex-1 py-1.5 text-xs font-medium rounded-lg transition-all ${
                    authMode === 'register'
                      ? 'bg-blue-600 text-white shadow-md'
                      : 'text-slate-400 hover:text-white'
                  }`}
                >
                  Register Company
                </button>
              </div>

              {authError && (
                <div className="p-3 text-xs bg-red-950/40 border border-red-800/50 text-red-300 rounded-xl">
                  {authError}
                </div>
              )}

              <form onSubmit={handleAuthSubmit} className="space-y-4">
                {authMode === 'register' && (
                  <>
                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Company / Organization Name</label>
                      <input
                        type="text"
                        required
                        value={authCompanyName}
                        onChange={(e) => setAuthCompanyName(e.target.value)}
                        placeholder="e.g. Acme Corporation"
                        className="w-full bg-slate-950/60 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Your Full Name</label>
                      <input
                        type="text"
                        required
                        value={authName}
                        onChange={(e) => setAuthName(e.target.value)}
                        placeholder="Alice Founder"
                        className="w-full bg-slate-950/60 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>
                  </>
                )}

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Email Address</label>
                  <input
                    type="email"
                    required
                    value={authEmail}
                    onChange={(e) => setAuthEmail(e.target.value)}
                    placeholder="name@company.com"
                    className="w-full bg-slate-950/60 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Password</label>
                  <input
                    type="password"
                    required
                    value={authPassword}
                    onChange={(e) => setAuthPassword(e.target.value)}
                    placeholder="••••••••"
                    className="w-full bg-slate-950/60 border border-slate-800 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                  />
                </div>

                <button
                  type="submit"
                  disabled={authLoading}
                  className="w-full py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-semibold shadow-lg shadow-blue-500/20 disabled:opacity-50 transition-all"
                >
                  {authLoading ? 'Processing...' : authMode === 'login' ? 'Sign In' : 'Create Company Account'}
                </button>
              </form>
            </div>
          ) : (
            /* Authenticated User: Connection List & Creation Flow */
            <div className="space-y-6">
              {/* Notifications */}
              {actionError && (
                <div className="p-3 text-xs bg-red-950/40 border border-red-800/50 text-red-300 rounded-xl flex items-center justify-between">
                  <span>{actionError}</span>
                  <button onClick={() => setActionError(null)} className="text-red-400 hover:text-white ml-2">✕</button>
                </div>
              )}

              {actionSuccess && (
                <div className="p-3 text-xs bg-emerald-950/40 border border-emerald-800/50 text-emerald-300 rounded-xl flex items-center justify-between">
                  <span>{actionSuccess}</span>
                  <button onClick={() => setActionSuccess(null)} className="text-emerald-400 hover:text-white ml-2">✕</button>
                </div>
              )}

              {/* Action Bar */}
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-semibold text-white">Your Database Connections</h3>
                  <p className="text-xs text-slate-400">
                    Passwords are encrypted with AES-256 at rest and strictly never exposed to the client.
                  </p>
                </div>

                <button
                  onClick={() => {
                    setShowAddForm(!showAddForm);
                    setTestResult(null);
                  }}
                  className="px-3.5 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white rounded-xl text-xs font-semibold shadow-md shadow-blue-500/10 flex items-center space-x-1.5 transition-all"
                >
                  <span>{showAddForm ? 'Cancel' : '+ Connect Database'}</span>
                </button>
              </div>

              {/* Add New Connection Form */}
              {showAddForm && (
                <form onSubmit={handleSaveConnection} className="bg-slate-950/60 border border-slate-800/80 rounded-2xl p-5 space-y-4 animate-fadeIn">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <h4 className="text-xs font-bold uppercase tracking-wider text-slate-300">New Database Connection</h4>
                    <div className="flex items-center space-x-2">
                      <span className="text-xs text-slate-400">Driver:</span>
                      <button
                        type="button"
                        onClick={() => handleDriverChange('mysql')}
                        className={`px-2.5 py-1 rounded-md text-xs font-semibold border ${
                          driver === 'mysql'
                            ? 'bg-blue-600/30 text-blue-300 border-blue-500/50'
                            : 'bg-slate-900 text-slate-400 border-slate-800 hover:text-white'
                        }`}
                      >
                        MySQL
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDriverChange('pgsql')}
                        className={`px-2.5 py-1 rounded-md text-xs font-semibold border ${
                          driver === 'pgsql'
                            ? 'bg-indigo-600/30 text-indigo-300 border-indigo-500/50'
                            : 'bg-slate-900 text-slate-400 border-slate-800 hover:text-white'
                        }`}
                      >
                        PostgreSQL
                      </button>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Connection Name</label>
                      <input
                        type="text"
                        required
                        value={connName}
                        onChange={(e) => setConnName(e.target.value)}
                        placeholder="e.g. Production Analytics Read Replica"
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Database Name</label>
                      <input
                        type="text"
                        required
                        value={database}
                        onChange={(e) => setDatabase(e.target.value)}
                        placeholder="e.g. analytics_db"
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Host</label>
                      <input
                        type="text"
                        required
                        value={host}
                        onChange={(e) => setHost(e.target.value)}
                        placeholder="127.0.0.1 or db.example.com"
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Port</label>
                      <input
                        type="number"
                        required
                        value={port}
                        onChange={(e) => setPort(Number(e.target.value))}
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Username (Read-Only Recommended)</label>
                      <input
                        type="text"
                        required
                        value={username}
                        onChange={(e) => setUsername(e.target.value)}
                        placeholder="readonly_user"
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-medium text-slate-300 mb-1">Password</label>
                      <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="••••••••"
                        className="w-full bg-slate-900 border border-slate-800 rounded-xl px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500"
                      />
                    </div>
                  </div>

                  {/* Security Advisory */}
                  <div className="p-3 bg-amber-950/20 border border-amber-800/30 rounded-xl text-[11px] text-amber-300/80 flex items-start space-x-2">
                    <span className="text-amber-400 font-bold">ℹ</span>
                    <span>
                      <strong>Security Best Practice:</strong> Provide a dedicated database user with <code>SELECT</code> privileges only. The platform will never modify your schema or execute write statements.
                    </span>
                  </div>

                  {/* Test Results Banner */}
                  {testResult && (
                    <div
                      className={`p-3 rounded-xl text-xs border flex items-center space-x-2 ${
                        testResult.success
                          ? 'bg-emerald-950/40 border-emerald-800/50 text-emerald-300'
                          : 'bg-red-950/40 border-red-800/50 text-red-300'
                      }`}
                    >
                      <span>{testResult.success ? '✓' : '⚠'}</span>
                      <span>{testResult.message}</span>
                    </div>
                  )}

                  {/* Form Actions */}
                  <div className="flex items-center justify-end space-x-3 pt-2">
                    <button
                      type="button"
                      disabled={isTesting}
                      onClick={handleTestConnection}
                      className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-medium border border-slate-700 disabled:opacity-50 transition-colors"
                    >
                      {isTesting ? 'Testing Connectivity...' : 'Test Connection'}
                    </button>

                    <button
                      type="submit"
                      disabled={isSaving}
                      className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-semibold shadow-md shadow-blue-500/20 disabled:opacity-50 transition-colors"
                    >
                      {isSaving ? 'Saving...' : 'Save & Verify Connection'}
                    </button>
                  </div>
                </form>
              )}

              {/* Connections List */}
              {isLoadingConnections ? (
                <div className="py-12 text-center text-xs text-slate-400">Loading database connections...</div>
              ) : connections.length === 0 ? (
                <div className="p-8 text-center border border-dashed border-slate-800 rounded-2xl bg-slate-950/20 space-y-2">
                  <div className="text-slate-500 text-2xl">🔌</div>
                  <h4 className="text-sm font-semibold text-slate-300">No Custom Databases Connected Yet</h4>
                  <p className="text-xs text-slate-500 max-w-md mx-auto">
                    Your queries currently run against the local demo MySQL database. Connect your company&apos;s MySQL or PostgreSQL database to introspect dynamic schemas.
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-3">
                  {connections.map((conn) => (
                    <div
                      key={conn.id}
                      className="p-4 bg-slate-950/40 border border-slate-800/80 rounded-xl space-y-2.5 hover:border-slate-700 transition-colors"
                    >
                      <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-3.5">
                          <div className={`w-9 h-9 rounded-xl flex items-center justify-center font-mono font-bold text-xs uppercase ${
                            conn.driver === 'mysql'
                              ? 'bg-blue-950/50 text-blue-400 border border-blue-800/40'
                              : 'bg-indigo-950/50 text-indigo-400 border border-indigo-800/40'
                          }`}>
                            {conn.driver === 'mysql' ? 'MY' : 'PG'}
                          </div>

                          <div>
                            <div className="flex items-center space-x-2">
                              <h4 className="text-xs font-semibold text-white">{conn.name}</h4>
                              <span className={`text-[10px] px-2 py-0.5 rounded-full uppercase tracking-wider font-semibold ${
                                conn.status === 'connected'
                                  ? 'bg-emerald-950/60 text-emerald-400 border border-emerald-800/50'
                                  : conn.status === 'failed'
                                  ? 'bg-red-950/60 text-red-400 border border-red-800/50'
                                  : 'bg-amber-950/60 text-amber-400 border border-amber-800/50'
                              }`}>
                                {conn.status}
                              </span>
                            </div>

                            <div className="text-[11px] text-slate-400 flex items-center space-x-3 mt-0.5 font-mono">
                              <span>{conn.username}@{conn.host}:{conn.port}/{conn.database}</span>
                              {conn.last_tested_at && (
                                <span className="text-slate-500">Probe: {new Date(conn.last_tested_at).toLocaleTimeString()}</span>
                              )}
                            </div>
                          </div>
                        </div>

                        <div className="flex items-center space-x-2">
                          <button
                            type="button"
                            onClick={() => handleCheckHealth(conn.id)}
                            disabled={checkingHealthId === conn.id}
                            className="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-emerald-400 rounded-lg text-xs font-medium border border-slate-800 hover:border-emerald-900/40 transition-colors flex items-center gap-1.5"
                            title="Probe live connection health & ping latency"
                          >
                            <span className={`w-2 h-2 rounded-full ${
                              checkingHealthId === conn.id
                                ? 'bg-amber-400 animate-ping'
                                : healthResults[conn.id]?.healthy
                                ? 'bg-emerald-400'
                                : healthResults[conn.id]
                                ? 'bg-rose-400'
                                : 'bg-slate-500'
                            }`} />
                            {checkingHealthId === conn.id
                              ? 'Probing...'
                              : healthResults[conn.id]
                              ? `${healthResults[conn.id].status === 'healthy' ? 'Healthy' : healthResults[conn.id].status} (${healthResults[conn.id].latency_ms}ms)`
                              : 'Check Health'}
                          </button>

                          <button
                            onClick={() => handleViewSchema(conn)}
                            disabled={schemaLoadingId === conn.id}
                            className="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-blue-400 rounded-lg text-xs font-medium border border-slate-800 hover:border-blue-900/40 transition-colors"
                          >
                            {schemaLoadingId === conn.id ? 'Introspecting...' : 'View Schema'}
                          </button>

                          <button
                            onClick={() => handleDeleteConnection(conn.id, conn.name)}
                            className="p-1.5 text-slate-500 hover:text-red-400 rounded-lg hover:bg-slate-900 transition-colors"
                            title="Delete Connection"
                          >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                          </button>
                        </div>
                      </div>

                      {healthResults[conn.id] && (
                        <div className={`text-[11px] px-3 py-1.5 rounded-lg flex items-center justify-between font-mono ${
                          healthResults[conn.id].healthy
                            ? 'bg-emerald-950/30 text-emerald-300 border border-emerald-500/20'
                            : 'bg-rose-950/30 text-rose-300 border border-rose-500/20'
                        }`}>
                          <span className="flex items-center gap-1.5">
                            <span>{healthResults[conn.id].healthy ? '✓' : '⚠'}</span>
                            <span>{healthResults[conn.id].message}</span>
                          </span>
                          <span className="text-slate-500 text-[10px]">
                            {new Date(healthResults[conn.id].timestamp).toLocaleTimeString()}
                          </span>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}

              {/* Dynamic Schema Inspector Drawer */}
              {selectedConnectionSchema && (
                <div className="p-5 bg-slate-950/80 border border-blue-900/30 rounded-2xl space-y-4 animate-fadeIn">
                  <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                      <h4 className="text-xs font-bold text-white flex items-center gap-2">
                        <span>Schema Explorer:</span>
                        <span className="text-blue-400 font-mono">{selectedConnectionSchema.connection.name}</span>
                      </h4>
                      <p className="text-[11px] text-slate-400">
                        Introspected {selectedConnectionSchema.schema.tables.length} tables from customer database.
                      </p>
                    </div>

                    <button
                      onClick={() => setSelectedConnectionSchema(null)}
                      className="text-slate-400 hover:text-white text-xs px-2.5 py-1 rounded bg-slate-900 border border-slate-800"
                    >
                      Close Schema
                    </button>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 max-h-72 overflow-y-auto pr-1">
                    {selectedConnectionSchema.schema.tables.map((table) => (
                      <div key={table.name} className="p-3 bg-slate-900/80 border border-slate-800 rounded-xl space-y-2">
                        <div className="flex items-center justify-between border-b border-slate-800/60 pb-1.5">
                          <span className="font-mono text-xs font-bold text-white">{table.name}</span>
                          <span className="text-[10px] text-slate-500">{table.columns.length} cols</span>
                        </div>
                        <div className="space-y-1 max-h-36 overflow-y-auto pr-1 font-mono text-[11px]">
                          {table.columns.map((col) => (
                            <div key={col.name} className="flex items-center justify-between text-slate-400">
                              <span className="text-slate-200">{col.name}</span>
                              <span className="text-[10px] text-blue-400/80">{col.type}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
