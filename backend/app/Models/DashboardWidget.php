<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'dashboard_id',
        'saved_query_id',
        'metric_id',
        'title',
        'visualization_type',
        'position',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Dashboard that contains this widget.
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * Saved query that provides the data source for this widget.
     */
    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(SavedQuery::class);
    }

    /**
     * Semantic metric associated with this widget (if any).
     */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(SemanticMetric::class, 'metric_id');
    }
}
