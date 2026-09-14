'use client';

import React from 'react';
import { UserRole } from '../../services/api';

interface RoleBadgeProps {
  role: UserRole;
  size?: 'sm' | 'md' | 'lg';
  showIcon?: boolean;
}

export function RoleBadge({ role, size = 'sm', showIcon = true }: RoleBadgeProps) {
  const sizeClasses = {
    sm: 'text-xs px-2.5 py-0.5 gap-1',
    md: 'text-xs px-3 py-1 gap-1.5 font-medium',
    lg: 'text-sm px-3.5 py-1.5 gap-2 font-semibold',
  };

  if (role === 'admin') {
    return (
      <span
        className={`inline-flex items-center rounded-full border border-purple-500/40 bg-purple-500/10 text-purple-300 shadow-sm shadow-purple-500/10 uppercase tracking-wider font-semibold ${sizeClasses[size]}`}
      >
        {showIcon && (
          <svg className="w-3.5 h-3.5 text-purple-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
        )}
        <span>Admin</span>
      </span>
    );
  }

  if (role === 'analyst') {
    return (
      <span
        className={`inline-flex items-center rounded-full border border-blue-500/40 bg-blue-500/10 text-blue-300 shadow-sm shadow-blue-500/10 uppercase tracking-wider font-semibold ${sizeClasses[size]}`}
      >
        {showIcon && (
          <svg className="w-3.5 h-3.5 text-blue-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        )}
        <span>Analyst</span>
      </span>
    );
  }

  // viewer
  return (
    <span
      className={`inline-flex items-center rounded-full border border-emerald-500/40 bg-emerald-500/10 text-emerald-300 shadow-sm shadow-emerald-500/10 uppercase tracking-wider font-semibold ${sizeClasses[size]}`}
    >
      {showIcon && (
        <svg className="w-3.5 h-3.5 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
        </svg>
      )}
      <span>Viewer</span>
    </span>
  );
}
