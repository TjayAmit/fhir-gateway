<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One submitted referral, tracked from acceptance to delivery.
 *
 * Notice what is absent: the referral itself. No patient name, no diagnosis, no bundle. The
 * row records that a referral exists, where it is going, and how far it has got. A retry
 * rebuilds the payload from the native record (D1), which also means a retry always carries
 * current data rather than a stale snapshot.
 *
 * @property string $message_id
 * @property string $source_system
 * @property string $native_id
 * @property string $request_type
 * @property string $destination_hcpn_id
 * @property string $target_facility
 * @property string|null $target_endpoint
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $fhir_task_id
 * @property string|null $fhir_service_request_id
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_attempt_at
 */
class OutboundReferral extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'message_id',
        'source_system',
        'native_id',
        'request_type',
        'destination_hcpn_id',
        'target_facility',
        'target_endpoint',
        'status',
        'attempts',
        'next_attempt_at',
        'last_error',
        'fhir_task_id',
        'fhir_service_request_id',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_hcpn_id', 'hcpn_id');
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * The status shape returned by GET /intake/v1/requests/{message_id}.
     *
     * @return array<string, mixed>
     */
    public function toStatusArray(): array
    {
        return [
            'message_id' => $this->message_id,
            'gateway_ref' => (string) $this->getKey(),
            'status' => $this->status,
            'request_type' => $this->request_type,
            'destination' => $this->destination_hcpn_id,
            'attempts' => $this->attempts,
            'task_id' => $this->fhir_task_id,
            'service_request_id' => $this->fhir_service_request_id,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }
}
