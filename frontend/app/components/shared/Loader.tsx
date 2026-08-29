import React from 'react';

export function Loader({ message = 'Analyzing request...' }: { message?: string }) {
  return (
    <div className="flex flex-col items-center justify-center p-8 space-y-4">
      <div className="relative w-12 h-12">
        <div className="absolute inset-0 rounded-full border-4 border-slate-800" />
        <div className="absolute inset-0 rounded-full border-4 border-t-blue-500 animate-spin" />
      </div>
      <p className="text-slate-400 text-sm animate-pulse">{message}</p>
    </div>
  );
}
