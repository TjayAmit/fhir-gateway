<?php

declare(strict_types=1);

use App\Models\Destination;

/**
 * The policy layer: who may call, as whom, and what they are allowed to see.
 */

function seedDestination(): void
{
    Destination::query()->create([
        'hcpn_id' => 'telemedicine-internal',
        'display_name' => 'Telemedicine Service',
        'nhfr_code' => 'DOH000000000000001',
        'endpoint_url' => 'http://telemedicine.test/fhir',
        'accepts' => ['referral', 'telemedicine'],
        'active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function submission(array $overrides = []): array
{
    $payload = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/intake/telemedicine-consult.json')),
        true,
    );

    return array_replace_recursive($payload, $overrides);
}

it('refuses every route without a token', function (string $method, string $uri) {
    asClient(); // install the client list, but send no Authorization header

    $this->json($method, $uri)
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertJsonPath('resourceType', 'OperationOutcome')
        ->assertJsonPath('issue.0.code', 'security');
})->with([
    ['GET', '/api/registry/v1/hcpn'],
    ['POST', '/api/intake/v1/requests'],
    ['GET', '/api/intake/v1/requests/8c2d5b31-77af-4e0a-b1c6-2d9f4a83e510'],
    ['GET', '/api/fhir/Task'],
]);

it('refuses an unrecognised token', function () {
    asClient();

    $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
        ->getJson('/api/registry/v1/hcpn')
        ->assertStatus(401);
});

it('will not let a client submit as another system', function () {
    seedDestination();

    // The telemedicine client claiming the referral system's name.
    $this->withHeaders(asClient('telemedicine'))
        ->postJson('/api/intake/v1/requests', submission())
        ->assertStatus(422)
        ->assertJsonPath('issue.0.expression.0', 'source.system');
});

it('ignores a facility supplied in the query and uses the token scope', function () {
    // Two referrals: one involving our facility, one between two others.
    App\Models\ReferralTask::query()->create([
        'fhir_task_id' => 'task-ours',
        'fhir_service_request_id' => 'sr-1',
        'direction' => 'outbound',
        'status' => 'requested',
        'requester_facility' => '3056',
        'performer_facility' => 'DOH000000000000001',
    ]);

    App\Models\ReferralTask::query()->create([
        'fhir_task_id' => 'task-theirs',
        'fhir_service_request_id' => 'sr-2',
        'direction' => 'outbound',
        'status' => 'requested',
        'requester_facility' => '9999',
        'performer_facility' => '8888',
    ]);

    // Asking for someone else's facility is not a supported parameter, and even if it were,
    // the scope comes from the token.
    $this->withHeaders(asClient())
        ->getJson('/api/fhir/Task?requester_facility=9999')
        ->assertStatus(400)
        ->assertJsonPath('issue.0.code', 'not-supported');

    $this->withHeaders(asClient())
        ->getJson('/api/fhir/Task')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('entry.0.resource.id', 'task-ours');
});

it('answers out-of-scope and not-found identically', function () {
    App\Models\ReferralTask::query()->create([
        'fhir_task_id' => 'task-theirs',
        'fhir_service_request_id' => 'sr-2',
        'direction' => 'outbound',
        'status' => 'requested',
        'requester_facility' => '9999',
        'performer_facility' => '8888',
    ]);

    // Both 404. Distinguishing them would confirm the referral exists.
    $existsButHidden = $this->withHeaders(asClient())->getJson('/api/fhir/Task/task-theirs');
    $doesNotExist = $this->withHeaders(asClient())->getJson('/api/fhir/Task/task-nowhere');

    $existsButHidden->assertStatus(404);
    $doesNotExist->assertStatus(404);

    expect($existsButHidden->json('issue.0.code'))->toBe($doesNotExist->json('issue.0.code'));
});

it('refuses a client with no facility scope rather than returning everything', function () {
    App\Models\ReferralTask::query()->create([
        'fhir_task_id' => 'task-ours',
        'fhir_service_request_id' => 'sr-1',
        'direction' => 'outbound',
        'status' => 'requested',
        'requester_facility' => '3056',
        'performer_facility' => 'DOH000000000000001',
    ]);

    $this->withHeaders(asClient('unscoped'))
        ->getJson('/api/fhir/Task')
        ->assertStatus(403)
        ->assertJsonPath('issue.0.code', 'forbidden');
});

it('throttles a client that hammers the search endpoint', function () {
    config()->set('fhir.rate_limits.search', 3);

    for ($i = 0; $i < 3; $i++) {
        $this->withHeaders(asClient())->getJson('/api/fhir/Task')->assertOk();
    }

    $this->withHeaders(asClient())
        ->getJson('/api/fhir/Task')
        ->assertStatus(429)
        ->assertJsonPath('issue.0.code', 'throttled');
});

it('audits reads with the scope filter that was applied', function () {
    $this->withHeaders(asClient())->getJson('/api/fhir/Task')->assertOk();

    $audit = App\Models\AuditEvent::query()->latest('id')->firstOrFail();

    expect($audit->action)->toBe('search')
        ->and($audit->actor_client_id)->toBe('referral')
        ->and($audit->actor_facility)->toBe('3056')
        // The proof that a scope filter was applied, recorded alongside the request.
        ->and($audit->scope_filter)->toBe('facility=3056')
        ->and($audit->result_count)->toBe(0);
});
