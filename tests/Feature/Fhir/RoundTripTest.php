<?php

declare(strict_types=1);

use DCarbone\PHPFHIRGenerated\Encoding\ResourceParser;
use DCarbone\PHPFHIRGenerated\Versions\R4\Version;

/**
 * Structural check on what we emit, using the vendored php-fhir R4 classes.
 *
 * This is the job php-fhir is actually good at: proving the output is well-formed base FHIR
 * R4 that a typed client can parse and re-serialise without loss. It cannot check a profile,
 * a slice or a binding — that is the validator's job (decision D4), and the reason the CI
 * conformance gate is not optional.
 *
 * Phase 0 established the limit the hard way: php-fhir drops fields it does not recognise
 * without any error at all. A green run here means "parses as R4", never "conforms to PH Core".
 */
it('emits bundles that parse as FHIR R4 and survive a round trip', function (string $fixture) {
    $path = base_path("tests/fixtures/fhir/{$fixture}.json");
    $original = json_decode((string) file_get_contents($path), true);

    $parsed = ResourceParser::parseJSON(new Version, (string) file_get_contents($path));

    expect($parsed)->not->toBeNull();

    $reserialised = json_decode(json_encode($parsed), true);

    expect($reserialised['resourceType'])->toBe('Bundle')
        ->and($reserialised['type'])->toBe('transaction')
        ->and($reserialised['entry'])->toHaveCount(count($original['entry']));

    // Every entry keeps its urn:uuid identity and its request verb — lose either and the
    // transaction stops being resolvable at the far end.
    foreach ($original['entry'] as $i => $entry) {
        expect($reserialised['entry'][$i]['fullUrl'])->toBe($entry['fullUrl'])
            ->and($reserialised['entry'][$i]['request']['method'])->toBe($entry['request']['method'])
            ->and($reserialised['entry'][$i]['request']['url'])->toBe($entry['request']['url']);
    }
})->with([
    'generated-referral-bundle',
    'generated-telemedicine-bundle',
    'ereferral-submission-bundle',
]);
