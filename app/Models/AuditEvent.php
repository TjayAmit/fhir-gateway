<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of who asked the gateway for what.
 *
 * This is the one table that holds patient identifiers on purpose. Once the gateway brokers
 * patient data, being able to say who looked up whom, and when, is a legal obligation rather
 * than a debugging convenience.
 *
 * Never update or delete rows outside a retention job.
 */
class AuditEvent extends Model
{
    protected $fillable = [
        'occurred_at',
        'actor_client_id',
        'actor_facility',
        'actor_ip',
        'action',
        'resource_type',
        'resource_id',
        'query',
        'scope_filter',
        'http_status',
        'result_count',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'query' => 'array',
        ];
    }
}
