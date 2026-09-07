<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Middleware\AuthenticateGatewayClient;
use App\Models\AuditEvent;
use Illuminate\Http\Request;

/**
 * Writes the audit trail.
 *
 * The actor is always taken from the authenticated client, never from the request body. A
 * caller that could name itself in an audit log could also name someone else.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $query  search parameters or submission identifiers
     */
    public function record(
        Request $request,
        string $action,
        int $httpStatus,
        float $startedAt,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $query = [],
        ?string $scopeFilter = null,
        ?int $resultCount = null,
    ): void {
        $client = $request->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);

        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_client_id' => is_array($client) ? $client['id'] : null,
            'actor_facility' => is_array($client) ? $client['facility'] : null,
            'actor_ip' => $request->ip(),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'query' => $query === [] ? null : $query,
            'scope_filter' => $scopeFilter,
            'http_status' => $httpStatus,
            'result_count' => $resultCount,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
