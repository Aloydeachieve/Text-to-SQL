'use client';

import React, { useState, useEffect } from 'react';
import {
  AuthUser,
  CompanyMember,
  UserRole,
  AddMemberParams,
  fetchCompanyMembers,
  addCompanyMember,
  updateCompanyMemberRole,
  removeCompanyMember,
} from '../../services/api';
import { TeamMemberList } from './TeamMemberList';
import { AddMemberModal } from './AddMemberModal';

interface TeamViewProps {
  currentUser: AuthUser | null;
  onOpenAuthModal?: () => void;
}

export function TeamView({ currentUser, onOpenAuthModal }: TeamViewProps) {
  const [members, setMembers] = useState<CompanyMember[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const isAdmin = currentUser?.role === 'admin';

  useEffect(() => {
    if (!currentUser) return;

    let ignore = false;
    fetchCompanyMembers()
      .then((data) => {
        if (!ignore) {
          setMembers(data);
          setIsLoading(false);
        }
      })
      .catch((err: unknown) => {
        if (!ignore) {
          const message = err instanceof Error ? err.message : 'Failed to fetch team members.';
          setError(message);
          setIsLoading(false);
        }
      });

    return () => {
      ignore = true;
    };
  }, [currentUser]);

  const handleAddMember = async (params: AddMemberParams) => {
    const newMember = await addCompanyMember(params);
    setMembers((prev) => [...prev, newMember]);
    setSuccessMessage(`Successfully added ${newMember.name} as ${newMember.role}.`);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const handleUpdateRole = async (memberId: number, newRole: UserRole) => {
    const updated = await updateCompanyMemberRole(memberId, { role: newRole });
    setMembers((prev) => prev.map((m) => (m.id === memberId ? updated : m)));
    setSuccessMessage(`Updated role for ${updated.name} to ${updated.role}.`);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const handleRemoveMember = async (memberId: number) => {
    await removeCompanyMember(memberId);
    setMembers((prev) => prev.filter((m) => m.id !== memberId));
    setSuccessMessage('Member removed from team.');
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  if (!currentUser) {
    return (
      <div className="max-w-4xl mx-auto py-16 px-4 text-center space-y-6 animate-fadeIn">
        <div className="w-16 h-16 mx-auto rounded-2xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-3xl text-purple-400 shadow-xl shadow-purple-500/10">
          👥
        </div>
        <div className="space-y-2">
          <h2 className="text-2xl font-bold text-white tracking-tight">Team Collaboration & Role Permissions</h2>
          <p className="text-sm text-slate-400 max-w-md mx-auto">
            Manage your organization members, assign roles (Admin, Analyst, Viewer), and collaborate on shared queries and dashboards.
          </p>
        </div>
        <div>
          <button
            type="button"
            onClick={onOpenAuthModal}
            className="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-purple-600/25 transition-all"
          >
            Sign In or Register Organization
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-8 animate-fadeIn max-w-7xl mx-auto">
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-5">
        <div>
          <div className="flex items-center space-x-3">
            <h1 className="text-2xl font-bold text-white tracking-tight">Team Members</h1>
            <span className="text-xs font-mono font-semibold px-2.5 py-1 rounded-full bg-slate-800 text-slate-300 border border-slate-700">
              {members.length} {members.length === 1 ? 'member' : 'members'}
            </span>
          </div>
          <p className="text-xs text-slate-400 mt-1">
            Organization: <strong className="text-slate-200">{currentUser.company?.name || currentUser.company_name || 'My Organization'}</strong>
          </p>
        </div>

        {isAdmin && (
          <button
            type="button"
            onClick={() => setIsAddModalOpen(true)}
            className="inline-flex items-center space-x-2 px-4 py-2 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-500 hover:to-blue-500 text-white text-xs font-semibold rounded-xl shadow-lg shadow-purple-600/20 transition-all self-start sm:self-auto"
          >
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            <span>Add Member</span>
          </button>
        )}
      </div>

      {/* Success Notification */}
      {successMessage && (
        <div className="p-3 text-xs text-emerald-300 bg-emerald-950/40 border border-emerald-800/60 rounded-xl flex items-center justify-between animate-fadeIn">
          <span>{successMessage}</span>
          <button onClick={() => setSuccessMessage(null)} className="text-emerald-400 hover:text-emerald-200">✕</button>
        </div>
      )}

      {/* Error Notification */}
      {error && (
        <div className="p-3 text-xs text-rose-300 bg-rose-950/40 border border-rose-800/60 rounded-xl flex items-center justify-between animate-fadeIn">
          <span>{error}</span>
          <button onClick={() => setError(null)} className="text-rose-400 hover:text-rose-200">✕</button>
        </div>
      )}

      {/* Non-Admin Notice */}
      {!isAdmin && (
        <div className="p-4 rounded-xl border border-blue-500/20 bg-blue-950/20 flex items-start space-x-3 text-xs text-slate-300">
          <svg className="w-5 h-5 text-blue-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <span className="font-semibold text-white">Directory View:</span> You have{' '}
            <strong className="text-blue-300 capitalize">{currentUser.role}</strong> permissions. Only organization administrators can invite members, change roles, or remove accounts.
          </div>
        </div>
      )}

      {/* Member Table */}
      <TeamMemberList
        members={members}
        currentUser={currentUser}
        onUpdateRole={handleUpdateRole}
        onRemoveMember={handleRemoveMember}
        isLoading={isLoading}
      />

      {/* Roles & Permissions Reference Cards */}
      <div className="pt-6 border-t border-slate-800/60">
        <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">
          Role Permissions Overview
        </h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="p-4 rounded-xl border border-purple-500/20 bg-purple-950/10 space-y-2">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-2 rounded-full bg-purple-400"></span>
              <h4 className="text-xs font-bold text-purple-300 uppercase tracking-wide">Administrator</h4>
            </div>
            <ul className="text-xs text-slate-400 space-y-1 list-disc list-inside">
              <li>Manage database connections</li>
              <li>Invite, promote, and remove team members</li>
              <li>Full access to all queries and dashboards</li>
              <li>Execute arbitrary SQL queries</li>
            </ul>
          </div>

          <div className="p-4 rounded-xl border border-blue-500/20 bg-blue-950/10 space-y-2">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-2 rounded-full bg-blue-400"></span>
              <h4 className="text-xs font-bold text-blue-300 uppercase tracking-wide">Analyst</h4>
            </div>
            <ul className="text-xs text-slate-400 space-y-1 list-disc list-inside">
              <li>Generate and execute SQL queries</li>
              <li>Use existing database connections</li>
              <li>Create and manage own saved queries & dashboards</li>
              <li>Share resources with organization</li>
            </ul>
          </div>

          <div className="p-4 rounded-xl border border-emerald-500/20 bg-emerald-950/10 space-y-2">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
              <h4 className="text-xs font-bold text-emerald-300 uppercase tracking-wide">Viewer</h4>
            </div>
            <ul className="text-xs text-slate-400 space-y-1 list-disc list-inside">
              <li>View and execute company-shared saved queries</li>
              <li>View, filter, and export company dashboards</li>
              <li>Read-only team directory view</li>
              <li>No arbitrary SQL or credential access</li>
            </ul>
          </div>
        </div>
      </div>

      {/* Add Member Modal */}
      <AddMemberModal
        isOpen={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        onAdd={handleAddMember}
      />
    </div>
  );
}
