import React, { useState } from 'react';

interface QueryInputProps {
  onSubmit: (question: string) => void;
  isLoading: boolean;
}

const QUICK_QUESTIONS = [
  "How many customers do we have?",
  "What are our top-selling products?",
  "Show monthly revenue.",
  "Which customers placed the most orders?",
  "How many orders were placed this month?"
];

export function QueryInput({ onSubmit, isLoading }: QueryInputProps) {
  const [question, setQuestion] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (question.trim() && !isLoading) {
      onSubmit(question.trim());
    }
  };

  const handleQuickQuestion = (q: string) => {
    setQuestion(q);
    onSubmit(q);
  };

  return (
    <div className="space-y-5">
      <form onSubmit={handleSubmit} className="relative">
        <textarea
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          placeholder="Ask your database a question... (e.g. 'What are our top-selling products?')"
          rows={3}
          disabled={isLoading}
          className="w-full bg-slate-905/45 border border-slate-800/80 rounded-2xl p-4 pr-32 text-slate-100 placeholder-slate-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/30 transition-all duration-200 resize-none leading-relaxed text-sm"
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              handleSubmit(e);
            }
          }}
        />
        
        <button
          type="submit"
          disabled={isLoading || !question.trim()}
          className="absolute right-3.5 bottom-3.5 bg-gradient-to-tr from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold text-xs px-5 py-2.5 rounded-xl shadow-lg shadow-blue-500/10 hover:shadow-blue-500/20 active:scale-95 transition-all duration-150 disabled:opacity-50 disabled:pointer-events-none"
        >
          {isLoading ? 'Running...' : 'Execute'}
        </button>
      </form>

      <div className="space-y-2.5">
        <span className="text-[10px] font-bold text-slate-500 tracking-wider uppercase block">Quick Start Prompts</span>
        <div className="flex flex-wrap gap-2">
          {QUICK_QUESTIONS.map((q, idx) => (
            <button
              key={idx}
              type="button"
              onClick={() => handleQuickQuestion(q)}
              disabled={isLoading}
              className="bg-slate-900/30 hover:bg-slate-800/30 text-slate-300 text-xs px-4 py-2.5 rounded-full border border-slate-850 hover:border-slate-700/60 transition-all duration-150 disabled:opacity-50 cursor-pointer"
            >
              {q}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
