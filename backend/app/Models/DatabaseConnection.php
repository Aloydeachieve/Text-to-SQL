<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatabaseConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'status',
        'last_tested_at',
    ];

    /**
     * Attributes hidden from array and JSON serialization.
     * Guaranteed never to leak password to API responses or frontend state.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Attribute casting.
     * Password is automatically encrypted at rest and decrypted on retrieval.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Company that owns this connection.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
