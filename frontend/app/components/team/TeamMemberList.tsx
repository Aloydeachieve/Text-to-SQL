'use client';

import React, { useState } from 'react';
import { CompanyMember, UserRole, AuthUser } from '../../services/api';
import { RoleBadge } from './RoleBadge';

interface TeamMemberListProps {
  members: CompanyMember[];
  currentUser: AuthUser;
  onUpdateRole: (memberId: number, newRole: UserRole) => Promise<void>;
  onRemoveMember: (memberId: number) => Promise<void>;
  isLoading?: boolean;
}

export function TeamMemberList({
  members,
  currentUser,
  onUpdateRole,
  onRemoveMember,
  isLoading = false,
}: TeamMemberListProps) {
  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const isAdmin = currentUser.role === 'admin';

  const handleRoleChange = async (member: CompanyMember, newRole: UserRole) => {
    if (member.role === newRole) return;
    setActionError(null);
    setUpdatingId(member.id);
    try {
      await onUpdateRole(member.id, newRole);
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to update member role.';
      setActionError(message);
    } finally {
      setUpdatingId(null);
    }
  };

  const handleRemove = async (member: CompanyMember) => {
    if (!window.confirm(`Are you sure you want to remove ${member.name} (${member.email}) from the team?`)) {
      return;
    }
    setActionError(null);
    setDeletingId(member.id);
    try {
      await onRemoveMember(member.id);
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to remove member.';
      setActionError(message);
    } finally {
      setDeletingId(null);
    }
  };

  if (isLoading) {
    return (
      <div className="py-12 flex flex-col items-center justify-center space-y-3">
        <svg className="w-8 h-8 animate-spin text-purple-500" viewBox="0 0 24 24" fill="none">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
        </svg>
        <span className="text-xs text-slate-400">Loading team members...</span>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {actionError && (
        <div className="p-3 text-xs text-rose-300 bg-rose-950/40 border border-rose-800/60 rounded-xl flex items-center justify-between">
          <span>{actionError}</span>
          <button
            onClick={() => setActionError(null)}
            className="text-rose-400 hover:text-rose-200 ml-2"
          >
            ✕
          </button>
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900/50 backdrop-blur-md">
        <table className="w-full text-left border-collapse">
          <thead>
            <tr className="border-b border-slate-800/80 bg-slate-950/40 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
              <th className="py-3 px-4">Member</th>
              <th className="py-3 px-4">Role</th>
              <th className="py-3 px-4 hidden md:table-cell">Joined</th>
              <th className="py-3 px-4 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-800/50 text-sm">
            {members.map((member) => {
              const isSelf = member.id === currentUser.id;
              const isUpdating = updatingId === member.id;
              const isDeleting = deletingId === member.id;

              return (
                <tr
                  key={member.id}
                  className="hover:bg-slate-800/30 transition-colors group"
                >
                  <td className="py-3.5 px-4">
                    <div className="flex items-center space-x-3">
                      <div className="w-9 h-9 rounded-full bg-gradient-to-tr from-purple-600/30 to-blue-500/30 border border-purple-500/30 flex items-center justify-center font-bold text-xs text-purple-200 shrink-0">
                        {member.name.charAt(0).toUpperCase()}
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center space-x-2">
                          <span className="font-medium text-slate-200 truncate">
                            {member.name}
                          </span>
                          {isSelf && (
                            <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 font-semibold border border-purple-500/30">
                              You
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-slate-400 truncate">{member.email}</div>
                      </div>
                    </div>
                  </td>

                  <td className="py-3.5 px-4">
                    <div className="flex items-center space-x-2">
                      <RoleBadge role={member.role} />
                      {isAdmin && !isSelf && (
                        <select
                          value={member.role}
                          disabled={isUpdating || isDeleting}
                          onChange={(e) => handleRoleChange(member, e.target.value as UserRole)}
                          className="text-xs bg-slate-950 border border-slate-800 rounded-lg px-2 py-1 text-slate-300 focus:outline-none focus:border-purple-500 transition-colors disabled:opacity-50"
                        >
                          <option value="viewer">Viewer</option>
                          <option value="analyst">Analyst</option>
                          <option value="admin">Admin</option>
                        </select>
                      )}
                    </div>
                  </td>

                  <td className="py-3.5 px-4 hidden md:table-cell text-xs text-slate-400">
                    {new Date(member.created_at).toLocaleDateString(undefined, {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                    })}
                  </td>

                  <td className="py-3.5 px-4 text-right">
                    {isAdmin && !isSelf ? (
                      <button
                        type="button"
                        onClick={() => handleRemove(member)}
                        disabled={isDeleting || isUpdating}
                        className="text-xs text-rose-400 hover:text-rose-300 bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/20 px-2.5 py-1 rounded-lg transition-colors disabled:opacity-50 inline-flex items-center space-x-1"
                      >
                        {isDeleting ? (
                          <>
                            <svg className="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
                              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                            </svg>
                            <span>Removing...</span>
                          </>
                        ) : (
                          <span>Remove</span>
                        )}
                      </button>
                    ) : (
                      <span className="text-xs text-slate-600 italic">
                        {isSelf ? 'Self' : 'Read-only'}
                      </span>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
