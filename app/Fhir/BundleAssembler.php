<?php

declare(strict_types=1);

namespace App\Fhir;

use Illuminate\Support\Str;

/**
 * Builds the transaction Bundle that is the eReferral wire format.
 *
 * The shape is taken from the IG's own Bundle-ExampleERefSubmissionBundle: a `transaction`
 * with `urn:uuid` fullUrls, identity resources sent as conditional PUTs keyed on a national
 * identifier, and the clinical and workflow payload sent as POSTs.
 *
 * Why conditional PUT for identity: the receiver may or may not already hold this patient,
 * practitioner or facility, and we have no way to know. `PUT Patient?identifier=...` means
 * "make this true" — it creates on first contact and updates on every later referral, which
 * is exactly how a missing national ID gets backfilled onto an existing record without us
 * having to ask first.
 *
 * Entries are added in dependency order because a reference has to exist before the resource
 * that points at it can be built. The Bundle itself is order-independent once assembled.
 */
final class BundleAssembler
{
    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /** @var array<string, string> identifier key => urn:uuid, so the same thing is sent once */
    private array $upserted = [];

    /**
     * Conditional upsert keyed on a business identifier.
     *
     * @param  array<string, mixed>  $resource
     * @return string the urn:uuid to reference this entry by
     */
    public function upsert(array $resource, string $identifierSystem, string $identifierValue): string
    {
        $type = (string) $resource['resourceType'];
        $key = $type.'|'.$identifierSystem.'|'.$identifierValue;

        if (isset($this->upserted[$key])) {
            return $this->upserted[$key];
        }

        $fullUrl = self::newUrn();

        $this->entries[] = [
            'fullUrl' => $fullUrl,
            'resource' => $resource,
            'request' => [
                'method' => 'PUT',
                // Unencoded, matching the IG's own example verbatim.
                'url' => $type.'?identifier='.$identifierSystem.'|'.$identifierValue,
            ],
        ];

        return $this->upserted[$key] = $fullUrl;
    }

    /**
     * Plain create. Used for everything that has no stable business identifier of its own.
     *
     * @param  array<string, mixed>  $resource
     */
    public function create(array $resource): string
    {
        $fullUrl = self::newUrn();

        $this->entries[] = [
            'fullUrl' => $fullUrl,
            'resource' => $resource,
            'request' => [
                'method' => 'POST',
                'url' => (string) $resource['resourceType'],
            ],
        ];

        return $fullUrl;
    }

    /**
     * Conditional create — succeeds as a no-op if a match already exists.
     *
     * Used for the Task, so that a replayed submission does not open a second referral at the
     * receiving end even if our own idempotency check has been bypassed.
     *
     * @param  array<string, mixed>  $resource
     */
    public function createIfNoneExist(array $resource, string $identifierSystem, string $identifierValue): string
    {
        $fullUrl = self::newUrn();

        $this->entries[] = [
            'fullUrl' => $fullUrl,
            'resource' => $resource,
            'request' => [
                'method' => 'POST',
                'url' => (string) $resource['resourceType'],
                'ifNoneExist' => 'identifier='.$identifierSystem.'|'.$identifierValue,
            ],
        ];

        return $fullUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => $this->entries,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private static function newUrn(): string
    {
        return 'urn:uuid:'.Str::uuid()->toString();
    }
}
