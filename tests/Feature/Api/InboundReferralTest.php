<?php

declare(strict_types=1);

use App\Fhir\ReferralBundleFactory;
use App\Models\Destination;
use App\Models\ReferralTask;
use Illuminate\Support\Facades\Http;

/**
 * Flow 2 — a facility refers a patient to us.
 *
 * The gateway translates the Bundle back into intake JSON and hands it to the native system
 * over the native contract. What that system does with it is deliberately none of our business.
 */

/** Our own facility, set up as a system that can be handed a referral. */
function ourSystem(array $overrides = []): Destination
{
    return Destination::query()->create(array_merge([
        'hcpn_id' => 'referral-internal',
        'display_name' => 'Referral System',
        'nhfr_code' => '3056',
        'endpoint_url' => 'http://receiver.test/fhir',
        'inbound_url' => 'http://referral.internal/gateway/referrals',
        'patient_search_url' => 'http://referral.internal/gateway/patients',
        'auth_credential_key' => 'referral_token',
        'accepts' => ['referral'],
        'active' => true,
    ], $overrides));
}

/**
 * A referral addressed to us, in the wire format a sending facility would use.
 *
 * Built with our own factory: the fixture is a Bundle whose performer is our facility, which
 * is exactly what arrives when someone refers a patient in.
 *
 * @return array<string, mixed>
 */
function inboundBundle(): array
{
    $intake = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/intake/referral-kalibo.json')),
        true,
    );

    // The referral is addressed to us.
    $us = Destination::query()->where('nhfr_code', '3056')->firstOrFail();

    return app(ReferralBundleFactory::class)->build($intake, $us);
}

it('translates an inbound bundle and hands it to the native system', function () {
    ourSystem();
    Http::fake(['referral.internal/*' => Http::response(['native_id' => 'NAT-8891'], 201)]);

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', inboundBundle())
        ->assertStatus(201)
        ->assertJsonPath('resourceType', 'Bundle')
        ->assertJsonPath('type', 'transaction-response')
        ->assertJsonPath('entry.0.response.status', '201 Created');

    // The native system received plain intake JSON, never a Bundle.
    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://referral.internal/gateway/referrals'
            && ! isset($body['resourceType'])
            && $body['patient']['family_name'] === 'Reyes'
            && $body['clinical']['diagnoses'][0]['code'] === '398254007'
            && $body['referral']['category'] === 'emergency';
    });
});

it('passes the sender idempotency key through to the native system', function () {
    ourSystem();
    Http::fake(['referral.internal/*' => Http::response(['native_id' => 'NAT-8891'], 201)]);

    $this->withHeaders(asClient())->postJson('/api/fhir', inboundBundle())->assertStatus(201);

    // So a replayed submission cannot open a second referral at the far end either.
    Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', '3f9a1c74-2b6e-4d18-9c33-1a5b7e0d4f21'));
});

it('records the referral as inbound workflow state, and nothing clinical', function () {
    ourSystem();
    Http::fake(['referral.internal/*' => Http::response(['native_id' => 'NAT-8891'], 201)]);

    $this->withHeaders(asClient())->postJson('/api/fhir', inboundBundle())->assertStatus(201);

    $task = ReferralTask::query()->firstOrFail();

    expect($task->direction)->toBe(ReferralTask::DIRECTION_INBOUND)
        ->and($task->business_status)->toBe('received')
        ->and($task->performer_facility)->toBe('3056')
        ->and(json_encode($task->getAttributes()))->not->toContain('Reyes');
});

it('refuses a referral addressed to a facility that is not ours', function () {
    // Build a referral aimed at some other hospital, then present it to us.
    $elsewhere = Destination::query()->create([
        'hcpn_id' => 'somewhere-else',
        'display_name' => 'Another Hospital',
        'nhfr_code' => '99999',
        'endpoint_url' => 'http://elsewhere.test/fhir',
        'active' => true,
    ]);

    $intake = json_decode(
        (string) file_get_contents(base_path('tests/fixtures/intake/referral-kalibo.json')),
        true,
    );

    $bundle = app(ReferralBundleFactory::class)->build($intake, $elsewhere);

    $elsewhere->delete();
    Http::fake();

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', $bundle)
        ->assertStatus(404)
        ->assertJsonPath('issue.0.code', 'not-found');

    Http::assertNothingSent();
});

it('refuses when the receiving system has no inbound endpoint', function () {
    ourSystem();
    // Build the bundle first, then take the endpoint away.
    $bundle = inboundBundle();
    Destination::query()->where('nhfr_code', '3056')->update(['inbound_url' => null]);

    Http::fake();

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', $bundle)
        ->assertStatus(503)
        ->assertJsonPath('issue.0.code', 'not-supported');

    // Nothing was accepted that we could not pass on.
    Http::assertNothingSent();
    expect(ReferralTask::query()->count())->toBe(0);
});

it('relays a native system rejection rather than swallowing it', function () {
    ourSystem();
    Http::fake([
        'referral.internal/*' => Http::response(['error' => 'Patient is not registered here'], 422),
    ]);

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', inboundBundle())
        ->assertStatus(502)
        ->assertJsonPath('issue.0.code', 'exception');

    expect(ReferralTask::query()->count())->toBe(0);
});

it('rejects anything that is not a transaction bundle', function () {
    ourSystem();

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', ['resourceType' => 'Patient'])
        ->assertStatus(400)
        ->assertJsonPath('issue.0.code', 'structure');

    $this->withHeaders(asClient())
        ->postJson('/api/fhir', ['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => []])
        ->assertStatus(422)
        ->assertJsonPath('issue.0.code', 'invalid');
});

it('needs a token like every other route', function () {
    ourSystem();
    asClient();

    $this->postJson('/api/fhir', inboundBundle())->assertStatus(401);
});
