<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Users belonging to this company.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Database connections belonging to this company.
     */
    public function databaseConnections(): HasMany
    {
        return $this->hasMany(DatabaseConnection::class);
    }

    /**
     * Saved queries belonging to this company.
     */
    public function savedQueries(): HasMany
    {
        return $this->hasMany(SavedQuery::class);
    }

    /**
     * Dashboards belonging to this company.
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }
}
