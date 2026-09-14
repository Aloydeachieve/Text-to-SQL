<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'description',
        'visibility',
    ];

    /**
     * Scope to dashboards visible to the given user based on company, role, and visibility.
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

        // Viewer role: strictly company-shared dashboards
        return $query->where('visibility', 'company');
    }

    /**
     * Check if a specific user can view/read this dashboard.
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
     * Check if a specific user can modify or delete this dashboard.
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
     * Company that owns this dashboard.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * User who created this dashboard.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Widgets placed onto this dashboard.
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position', 'asc');
    }
}
