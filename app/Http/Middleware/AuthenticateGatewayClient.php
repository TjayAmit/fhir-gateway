<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the systems that talk to the gateway.
 *
 * Clients are declared in config/fhir.php and their tokens come from the environment. This is
 * deliberately not a database table: the client list is two entries long, changes about never,
 * and keeping it out of the database means a database compromise does not hand over the
 * credentials for submitting referrals.
 *
 * The authenticated client is the source of truth for *who* is calling. Nothing downstream may
 * take the caller's identity or facility from the request body — that is the point of D7, and
 * IntakeRequest enforces the body agreeing with the token rather than the other way round.
 */
class AuthenticateGatewayClient
{
    /** Request attribute the resolved client is stored under. */
    public const ATTRIBUTE = 'gateway_client';

    public function handle(Request $request, Closure $next): Response
    {
        $modes = (array) config('fhir.client_auth.modes', ['token']);
        $client = null;

        if (in_array('mtls', $modes, true)) {
            $client = $this->resolveByCertificate($request);
        }

        if ($client === null && in_array('token', $modes, true)) {
            $token = $request->bearerToken();

            if ($token !== null && $token !== '') {
                $client = $this->resolve($token);
            }
        }

        if ($client === null) {
            return $this->deny(
                in_array('mtls', $modes, true)
                    ? 'A recognised client certificate or bearer token is required.'
                    : 'A valid bearer token is required.'
            );
        }

        $request->attributes->set(self::ATTRIBUTE, $client);

        return $next($request);
    }

    /**
     * Mutual TLS, terminated at the reverse proxy.
     *
     * PHP never sees the handshake, so the proxy tells us two things: whether the certificate
     * chain verified, and its fingerprint. We refuse unless verification actually succeeded —
     * a fingerprint alone proves nothing, since anyone can send a header.
     *
     * This means the proxy must strip both headers from inbound requests. If it does not, a
     * caller can forge either one, and no amount of care here helps.
     *
     * @return array{id: string, facility: ?string, systems: list<string>}|null
     */
    private function resolveByCertificate(Request $request): ?array
    {
        $verify = (string) $request->header((string) config('fhir.client_auth.mtls_verify_header'), '');

        // nginx says SUCCESS; Apache says SUCCESS too. Anything else is a failed or absent chain.
        if (strcasecmp($verify, 'SUCCESS') !== 0) {
            return null;
        }

        $fingerprint = (string) $request->header((string) config('fhir.client_auth.mtls_fingerprint_header'), '');

        if ($fingerprint === '') {
            return null;
        }

        /** @var array<string, array<string, mixed>> $clients */
        $clients = (array) config('fhir.clients', []);
        $matched = null;

        foreach ($clients as $id => $client) {
            $configured = (string) ($client['fingerprint'] ?? '');

            if ($configured !== '' && hash_equals($configured, $fingerprint)) {
                $matched = [
                    'id' => (string) $id,
                    'facility' => isset($client['facility']) ? (string) $client['facility'] : null,
                    'systems' => array_values((array) ($client['systems'] ?? [$id])),
                ];
            }
        }

        return $matched;
    }

    /**
     * Find the client whose token matches.
     *
     * Every configured token is compared, and always with hash_equals, so the time taken does
     * not reveal how much of a guessed token was correct.
     *
     * @return array{id: string, facility: ?string, systems: list<string>}|null
     */
    private function resolve(string $token): ?array
    {
        $matched = null;

        /** @var array<string, array<string, mixed>> $clients */
        $clients = (array) config('fhir.clients', []);

        foreach ($clients as $id => $client) {
            $configured = (string) ($client['token'] ?? '');

            if ($configured === '') {
                continue;
            }

            if (hash_equals($configured, $token)) {
                $matched = [
                    'id' => (string) $id,
                    'facility' => isset($client['facility']) ? (string) $client['facility'] : null,
                    'systems' => array_values((array) ($client['systems'] ?? [$id])),
                ];
            }
        }

        return $matched;
    }

    private function deny(string $message): Response
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity' => 'error',
                'code' => 'security',
                'diagnostics' => $message,
            ]],
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
