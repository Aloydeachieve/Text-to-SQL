import React from 'react';

interface CardProps {
  children: React.ReactNode;
  className?: string;
  title?: string;
}

export function Card({ children, className = '', title }: CardProps) {
  return (
    <div className={`glass-card p-6 rounded-xl border border-slate-800/40 bg-slate-900/30 backdrop-blur-md ${className}`}>
      {title && (
        <h3 className="text-lg font-semibold mb-4 text-slate-200 border-b border-slate-800/40 pb-2">
          {title}
        </h3>
      )}
      {children}
    </div>
  );
}
