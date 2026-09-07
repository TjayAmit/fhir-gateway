<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Destination;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks FHIR REST to one destination.
 *
 * Credentials never live in the destinations table — the row stores a lookup key and the
 * secret is resolved from config at call time, so the registry can be dumped, seeded or
 * inspected without leaking anything.
 */
final class FhirClient
{
    public function __construct(private readonly Destination $destination) {}

    /**
     * Search for resources of a type by a token search parameter.
     *
     * @return list<array<string, mixed>> the matched resources, unwrapped from the searchset
     */
    public function searchByToken(string $resourceType, string $parameter, string $system, string $value): array
    {
        $response = $this->request()->get($this->url($resourceType), [
            $parameter => $system.'|'.$value,
        ]);

        $response->throw();

        $entries = $response->json('entry') ?? [];

        return array_values(array_filter(array_map(
            static fn (array $entry): ?array => $entry['resource'] ?? null,
            $entries,
        )));
    }

    /**
     * Post a transaction Bundle.
     *
     * @param  array<string, mixed>  $bundle
     */
    public function transaction(array $bundle): Response
    {
        return $this->request()
            ->withBody((string) json_encode($bundle), 'application/fhir+json')
            ->post($this->url(''));
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout((int) config('fhir.delivery.timeout', 30))
            ->withHeaders(['Accept' => 'application/fhir+json']);

        return match ($this->destination->auth_type) {
            'bearer' => $request->withToken($this->credential()),
            // mTLS is configured on the client certificate, not a header.
            'mtls' => $request->withOptions(['cert' => $this->credential()]),
            default => $request,
        };
    }

    private function credential(): string
    {
        $key = $this->destination->auth_credential_key;

        return $key === null ? '' : (string) config("services.hcpn.{$key}", '');
    }

    private function url(string $path): string
    {
        $base = rtrim((string) $this->destination->endpoint_url, '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}
