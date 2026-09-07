<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A facility a referral can be sent to.
 *
 * @property string $hcpn_id
 * @property string $display_name
 * @property string $nhfr_code
 * @property string|null $hcpn_code
 * @property string|null $city
 * @property string|null $phone
 * @property string|null $endpoint_url
 * @property string|null $auth_type
 * @property string|null $auth_credential_key
 * @property list<string>|null $accepts
 * @property bool $active
 */
class Destination extends Model
{
    protected $fillable = [
        'hcpn_id',
        'display_name',
        'city',
        'nhfr_code',
        'hcpn_code',
        'phone',
        'endpoint_url',
        'auth_type',
        'auth_credential_key',
        'accepts',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'accepts' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * A destination we can actually reach. A row without an endpoint is a directory entry,
     * not a delivery target — accepting a referral for one would queue it forever.
     */
    public function isDeliverable(): bool
    {
        return $this->active && $this->endpoint_url !== null && $this->endpoint_url !== '';
    }

    public function acceptsRequestType(string $requestType): bool
    {
        $accepts = $this->accepts;

        // No list means no restriction.
        return $accepts === null || $accepts === [] || in_array($requestType, $accepts, true);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The public shape. Deliberately omits the endpoint and anything auth-related — a sender
     * choosing a destination has no business knowing how we reach it.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'hcpn_id' => $this->hcpn_id,
            'display_name' => $this->display_name,
            'city' => $this->city,
            'nhfr_code' => $this->nhfr_code,
            'hcpn_code' => $this->hcpn_code,
            'accepts' => $this->accepts ?? ['referral', 'telemedicine'],
            'deliverable' => $this->isDeliverable(),
        ];
    }
}
