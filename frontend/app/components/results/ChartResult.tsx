import React, { useState } from 'react';

interface ChartResultProps {
  results: Array<Record<string, any>>;
}

export function ChartResult({ results }: { results: Array<Record<string, any>> }) {
  const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);

  if (!results || results.length <= 1) return null;

  // 1. Identify columns
  const firstRow = results[0];
  const keys = Object.keys(firstRow);

  // We want to find:
  // - A label column: string/date
  // - A value column: numeric (number or string representation of float)
  let labelKey = '';
  let valueKey = '';

  for (const key of keys) {
    const val = firstRow[key];
    // Check if numeric
    if (typeof val === 'number' && !labelKey.includes('id') && key !== 'id') {
      valueKey = key;
    } else if (typeof val === 'string' && !isNaN(Number(val)) && !key.toLowerCase().includes('id') && key !== 'id') {
      valueKey = key;
    } else if (!labelKey) {
      labelKey = key;
    }
  }

  // Fallback if none found
  if (!labelKey) labelKey = keys[0];
  if (!valueKey) {
    // Look for any numeric key
    const numKey = keys.find(k => k !== labelKey && !k.toLowerCase().includes('id'));
    if (numKey) valueKey = numKey;
  }

  // If we still don't have a value key or there's only 1 column, we can't chart it
  if (!valueKey || labelKey === valueKey) return null;

  // 2. Parse data
  const data = results.map(row => {
    const rawVal = row[valueKey];
    const numVal = typeof rawVal === 'number' ? rawVal : parseFloat(rawVal) || 0;
    return {
      label: String(row[labelKey]),
      value: numVal,
      displayValue: typeof rawVal === 'number' && (valueKey.toLowerCase().includes('price') || valueKey.toLowerCase().includes('amount') || valueKey.toLowerCase().includes('revenue') || valueKey.toLowerCase().includes('spent')) 
        ? `$${numVal.toFixed(2)}` 
        : numVal.toLocaleString()
    };
  });

  const maxVal = Math.max(...data.map(d => d.value), 1);
  const minVal = 0;

  // Determine Chart Type
  // If labels look like dates/months (e.g. YYYY-MM), default to Line Chart
  const isChronological = data.some(d => /^\d{4}-\d{2}$/.test(d.label) || /^\d{4}-\d{2}-\d{2}$/.test(d.label));
  const chartType = isChronological ? 'line' : 'bar';

  // SVG dimensions
  const width = 500;
  const height = 240;
  const paddingLeft = 60;
  const paddingRight = 20;
  const paddingTop = 20;
  const paddingBottom = 40;

  const chartWidth = width - paddingLeft - paddingRight;
  const chartHeight = height - paddingTop - paddingBottom;

  // Calculate coordinates
  const points = data.map((d, idx) => {
    const x = paddingLeft + (idx * (chartWidth / (data.length > 1 ? data.length - 1 : 1)));
    const y = paddingTop + chartHeight - ((d.value / maxVal) * chartHeight);
    return { x, y, ...d };
  });

  // Calculate bar positions
  const barGapRatio = 0.3; // 30% gap
  const totalBarWidth = chartWidth / data.length;
  const barWidth = totalBarWidth * (1 - barGapRatio);
  const barSpacing = totalBarWidth * barGapRatio;

  return (
    <div className="bg-slate-900/10 border border-slate-850 rounded-xl p-5 space-y-4">
      <div className="flex items-center justify-between">
        <h4 className="text-xs font-bold text-slate-400 uppercase tracking-wider">
          Visual Analysis: {valueKey.replace('_', ' ')} by {labelKey.replace('_', ' ')}
        </h4>
        <span className="text-[10px] bg-blue-500/15 text-blue-400 border border-blue-500/20 rounded-full px-2 py-0.5 font-bold uppercase tracking-wide">
          {chartType === 'line' ? 'Trend Line' : 'Distribution Bar'}
        </span>
      </div>

      <div className="relative h-60 w-full flex items-center justify-center">
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full h-full overflow-visible">
          <defs>
            {/* Gradients */}
            <linearGradient id="barGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#3b82f6" stopOpacity="0.85" />
              <stop offset="100%" stopColor="#8b5cf6" stopOpacity="0.2" />
            </linearGradient>
            <linearGradient id="areaGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#3b82f6" stopOpacity="0.35" />
              <stop offset="100%" stopColor="#3b82f6" stopOpacity="0.0" />
            </linearGradient>
          </defs>

          {/* Grid lines */}
          {[0, 0.25, 0.5, 0.75, 1].map((ratio, i) => {
            const y = paddingTop + chartHeight * ratio;
            const gridVal = maxVal - ((maxVal - minVal) * ratio);
            return (
              <g key={i}>
                <line
                  x1={paddingLeft}
                  y1={y}
                  x2={width - paddingRight}
                  y2={y}
                  stroke="rgba(148, 163, 184, 0.08)"
                  strokeWidth="1"
                  strokeDasharray="4 4"
                />
                <text
                  x={paddingLeft - 8}
                  y={y + 4}
                  textAnchor="end"
                  className="fill-slate-500 font-mono text-[9px]"
                >
                  {gridVal >= 1000 ? `${(gridVal / 1000).toFixed(1)}k` : Math.round(gridVal)}
                </text>
              </g>
            );
          })}

          {/* Render Bar Chart */}
          {chartType === 'bar' && data.map((d, idx) => {
            const h = (d.value / maxVal) * chartHeight;
            const x = paddingLeft + (idx * totalBarWidth) + (barSpacing / 2);
            const y = paddingTop + chartHeight - h;

            const isHovered = hoveredIdx === idx;

            return (
              <g key={idx}>
                <rect
                  x={x}
                  y={y}
                  width={barWidth}
                  height={h}
                  fill="url(#barGradient)"
                  rx="3"
                  className="transition-all duration-200 cursor-pointer"
                  onMouseEnter={() => setHoveredIdx(idx)}
                  onMouseLeave={() => setHoveredIdx(null)}
                  style={{
                    filter: isHovered ? 'drop-shadow(0px 0px 8px rgba(59,130,246,0.5))' : 'none',
                    opacity: hoveredIdx !== null && !isHovered ? 0.45 : 1
                  }}
                />
                {/* X Axis Label */}
                <text
                  x={x + barWidth / 2}
                  y={height - paddingBottom + 18}
                  textAnchor="middle"
                  className="fill-slate-400 text-[9px] font-medium"
                  style={{
                    opacity: data.length > 8 && idx % 2 !== 0 ? 0.3 : 1
                  }}
                >
                  {d.label.length > 12 ? `${d.label.substring(0, 10)}...` : d.label}
                </text>
              </g>
            );
          })}

          {/* Render Line Chart */}
          {chartType === 'line' && (
            <>
              {/* Shaded Area */}
              <path
                d={`
                  M ${points[0].x} ${paddingTop + chartHeight}
                  L ${points.map(p => `${p.x} ${p.y}`).join(' L ')}
                  L ${points[points.length - 1].x} ${paddingTop + chartHeight}
                  Z
                `}
                fill="url(#areaGradient)"
              />

              {/* Line path */}
              <path
                d={`M ${points.map(p => `${p.x} ${p.y}`).join(' L ')}`}
                fill="none"
                stroke="#3b82f6"
                strokeWidth="2.5"
                strokeLinecap="round"
                strokeLinejoin="round"
              />

              {/* Dots */}
              {points.map((p, idx) => {
                const isHovered = hoveredIdx === idx;
                return (
                  <g key={idx}>
                    <circle
                      cx={p.x}
                      cy={p.y}
                      r={isHovered ? 5.5 : 3.5}
                      fill={isHovered ? '#60a5fa' : '#3b82f6'}
                      stroke="#080c14"
                      strokeWidth={isHovered ? 2.5 : 1.5}
                      className="transition-all duration-150 cursor-pointer"
                      onMouseEnter={() => setHoveredIdx(idx)}
                      onMouseLeave={() => setHoveredIdx(null)}
                    />
                    <text
                      x={p.x}
                      y={height - paddingBottom + 18}
                      textAnchor="middle"
                      className="fill-slate-400 text-[9px] font-medium"
                      style={{
                        opacity: points.length > 8 && idx % 2 !== 0 ? 0.3 : 1
                      }}
                    >
                      {p.label}
                    </text>
                  </g>
                );
              })}
            </>
          )}

          {/* X Axis Line */}
          <line
            x1={paddingLeft}
            y1={paddingTop + chartHeight}
            x2={width - paddingRight}
            y2={paddingTop + chartHeight}
            stroke="rgba(148, 163, 184, 0.2)"
            strokeWidth="1"
          />
        </svg>

        {/* Hover Tooltip Overlay */}
        {hoveredIdx !== null && data[hoveredIdx] && (
          <div className="absolute bg-slate-950/90 border border-slate-800 rounded-lg p-2.5 shadow-2xl backdrop-blur-md text-xs pointer-events-none transition-all duration-100 z-10 max-w-[200px]"
            style={{
              top: chartType === 'bar' 
                ? `${Math.max(10, paddingTop + chartHeight - ((data[hoveredIdx].value / maxVal) * chartHeight) - 30)}px` 
                : `${Math.max(10, points[hoveredIdx].y - 45)}px`,
              left: chartType === 'bar'
                ? `${paddingLeft + (hoveredIdx * totalBarWidth) + (barWidth / 2) + 30}px`
                : `${points[hoveredIdx].x}px`,
              transform: 'translateX(-50%)'
            }}
          >
            <p className="font-bold text-slate-200 line-clamp-1">{data[hoveredIdx].label}</p>
            <p className="font-mono text-blue-400 mt-0.5">{data[hoveredIdx].displayValue}</p>
          </div>
        )}
      </div>
    </div>
  );
}
