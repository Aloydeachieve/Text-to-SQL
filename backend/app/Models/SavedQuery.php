<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedQuery extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'database_connection_id',
        'target_database_name',
        'is_demo',
        'visibility',
        'name',
        'description',
        'natural_language_question',
        'sql',
        'dialect',
        'result_visualization_type',
    ];

    protected function casts(): array
    {
        return [
            'is_demo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Scope to queries accessible by a given user based on tenant, role, and visibility.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $query->where('company_id', $user->company_id);

        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isAnalyst()) {
            return $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('visibility', 'company');
            });
        }

        // Viewer role: strictly company-shared queries
        return $query->where('visibility', 'company');
    }

    /**
     * Check if a specific user can view/read this saved query.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($this->company_id !== $user->company_id) {
            return false;
        }

        if ($user->isAdmin() || $this->visibility === 'company') {
            return true;
        }

        return $this->user_id === $user->id;
    }

    /**
     * Check if a specific user can modify or delete this saved query.
     */
    public function isEditableBy(User $user): bool
    {
        if ($this->company_id !== $user->company_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAnalyst()) {
            return $this->user_id === $user->id;
        }

        return false;
    }

    /**
     * Company that owns this saved query.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * User who created this saved query.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Database connection associated with this saved query.
     */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /**
     * Dashboard widgets that reference this saved query.
     */
    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }
}
