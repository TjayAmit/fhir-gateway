<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The destination picklist.
 *
 * This endpoint is why the referral and telemedicine systems can stay ignorant of the network
 * around them: they render this list, the user picks a row, and the `hcpn_id` goes back in the
 * submission. Endpoints and credentials are never in the response.
 */
class RegistryController extends Controller
{
    /**
     * GET /registry/v1/hcpn
     */
    public function index(Request $request): JsonResponse
    {
        $query = Destination::query()->active()->orderBy('display_name');

        if ($request->filled('accepts')) {
            $requestType = (string) $request->query('accepts');

            $query->where(function ($q) use ($requestType): void {
                $q->whereNull('accepts')
                    ->orWhereJsonContains('accepts', $requestType);
            });
        }

        // Default to hiding facilities we cannot deliver to; a sender that wants the full
        // directory can ask for it, but nothing is selectable that would queue forever.
        if (! $request->boolean('include_unreachable')) {
            $query->whereNotNull('endpoint_url');
        }

        $destinations = $query->get()->map->toPublicArray()->all();

        return response()->json([
            'data' => $destinations,
            'count' => count($destinations),
        ]);
    }
}
