<?php

declare(strict_types=1);

namespace App\Fhir;

use App\Models\Destination;
use RuntimeException;

/**
 * The inverse of ReferralBundleFactory: an eReferral submission Bundle back into intake JSON.
 *
 * This is the translation half of the inbound flow. When a facility sends us a referral, the
 * native systems must not have to read FHIR any more than they had to write it — so the
 * gateway turns the Bundle back into the same plain contract they already speak.
 *
 * It is deliberately built and tested ahead of the rest of the inbound flow. What happens to
 * the referral *after* translation depends on native tables and notification rules nobody has
 * specified yet; what the wire format means does not, and can be settled now.
 *
 * It also earns its keep immediately: running a payload out through the factory and back in
 * through this reader is how we prove the mapping loses nothing it did not mean to lose.
 */
final class ReferralBundleReader
{
    /** @var array<string, array<string, mixed>> fullUrl => resource */
    private array $byUrl = [];

    /**
     * @param  array<string, mixed>  $bundle  a transaction Bundle
     * @return array<string, mixed> the intake payload it represents
     */
    public function read(array $bundle): array
    {
        $this->index($bundle);

        $serviceRequest = $this->firstOfType('ServiceRequest')
            ?? throw new RuntimeException('Bundle contains no ServiceRequest; it is not a referral.');

        $task = $this->firstOfType('Task');
        $patient = $this->firstOfType('Patient')
            ?? throw new RuntimeException('Bundle contains no Patient.');

        $requesterRole = $this->resolve($serviceRequest['requester']['reference'] ?? null);
        $practitioner = $this->resolve($requesterRole['practitioner']['reference'] ?? null);
        $sourceOrg = $this->resolve($requesterRole['organization']['reference'] ?? null);
        $performerOrg = $this->resolve($serviceRequest['performer'][0]['reference'] ?? null);

        $isTelemedicine = str_starts_with(
            (string) ($serviceRequest['code']['text'] ?? ''),
            'Telemedicine consultation',
        );

        return array_filter([
            'message_id' => $this->taskMessageId($task),
            'sent_at' => $serviceRequest['authoredOn'] ?? null,
            'request_type' => $isTelemedicine ? 'telemedicine' : 'referral',
            'source' => $this->source($practitioner, $requesterRole, $sourceOrg, $serviceRequest),
            'destination' => $this->destination($performerOrg),
            'patient' => $this->patient($patient),
            'clinical' => $this->clinical($serviceRequest),
            $isTelemedicine ? 'telemedicine' : 'referral' => $this->request($serviceRequest, $isTelemedicine),
        ], static fn ($v): bool => $v !== null && $v !== []);
    }

    // ------------------------------------------------------------------ sections

    /**
     * @param  array<string, mixed>|null  $practitioner
     * @param  array<string, mixed>|null  $role
     * @param  array<string, mixed>|null  $org
     * @param  array<string, mixed>  $serviceRequest
     * @return array<string, mixed>
     */
    private function source(?array $practitioner, ?array $role, ?array $org, array $serviceRequest): array
    {
        $name = $practitioner['name'][0] ?? [];

        return array_filter([
            // Which of our systems sent it is not on the wire, and should not be guessed.
            // The caller sets it from the authenticated client.
            'system' => null,
            'record_id' => $serviceRequest['requisition']['value'] ?? null,
            'facility_code' => $this->identifierValue($org, Constants::ID_NHFR),
            'facility_name' => $org['name'] ?? null,
            'practitioner' => array_filter([
                'family_name' => $name['family'] ?? null,
                'given_names' => $name['given'] ?? null,
                'prc_license' => $this->identifierValue($practitioner, Constants::ID_PRC),
                'role' => $this->roleKey($role),
                'phone' => $this->telecom($practitioner, 'phone'),
                'email' => $this->telecom($practitioner, 'email'),
            ], static fn ($v): bool => $v !== null && $v !== []),
        ], static fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * Map the receiving facility back to a registry entry.
     *
     * The Bundle carries an NHFR code; our systems speak in opaque `hcpn_id`s. The registry is
     * what bridges the two, which is the same reason it exists in the outbound direction.
     *
     * @param  array<string, mixed>|null  $org
     * @return array<string, mixed>
     */
    private function destination(?array $org): array
    {
        $nhfr = $this->identifierValue($org, Constants::ID_NHFR);

        $hcpnId = $nhfr === null
            ? null
            : Destination::query()->where('nhfr_code', $nhfr)->value('hcpn_id');

        return array_filter([
            'hcpn_id' => $hcpnId,
            'nhfr_code' => $nhfr,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $patient
     * @return array<string, mixed>
     */
    private function patient(array $patient): array
    {
        $name = $patient['name'][0] ?? [];

        $types = [
            Constants::ID_PHILSYS => 'philsys',
            Constants::ID_PHILHEALTH => 'philhealth',
            (string) config('fhir.local_identifier_system') => 'local',
        ];

        $identifiers = [];

        foreach ($patient['identifier'] ?? [] as $identifier) {
            $type = $types[$identifier['system'] ?? ''] ?? null;

            if ($type !== null) {
                $identifiers[] = ['type' => $type, 'value' => (string) $identifier['value']];
            }
        }

        return array_filter([
            'identifiers' => $identifiers,
            'family_name' => $name['family'] ?? null,
            'given_names' => $name['given'] ?? null,
            'suffix' => $name['suffix'][0] ?? null,
            'birth_date' => $patient['birthDate'] ?? null,
            'sex' => $patient['gender'] ?? null,
            'mobile' => $this->telecom($patient, 'phone'),
            'address' => $this->address($patient['address'][0] ?? null),
        ], static fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return array<string, mixed>|null
     */
    private function address(?array $address): ?array
    {
        if ($address === null) {
            return null;
        }

        $levels = [
            Constants::EXT_REGION => 'region_psgc',
            Constants::EXT_PROVINCE => 'province_psgc',
            Constants::EXT_CITY => 'city_psgc',
            Constants::EXT_BARANGAY => 'barangay_psgc',
        ];

        $out = [
            'line' => $address['line'][0] ?? null,
            'postal_code' => $address['postalCode'] ?? null,
        ];

        foreach ($address['extension'] ?? [] as $extension) {
            $field = $levels[$extension['url'] ?? ''] ?? null;

            if ($field !== null) {
                $out[$field] = $extension['valueCoding']['code'] ?? null;
            }
        }

        return array_filter($out, static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $serviceRequest
     * @return array<string, mixed>
     */
    private function clinical(array $serviceRequest): array
    {
        $diagnoses = [];

        foreach ($serviceRequest['reasonReference'] ?? [] as $reference) {
            $condition = $this->resolve($reference['reference'] ?? null);

            if ($condition === null) {
                continue;
            }

            $coding = $condition['code']['coding'][0] ?? null;

            $diagnoses[] = array_filter([
                'system' => match ($coding['system'] ?? null) {
                    Constants::CS_ICD10 => 'icd10',
                    Constants::CS_SNOMED => 'snomed',
                    default => 'text',
                },
                'code' => $coding['code'] ?? null,
                'text' => $condition['code']['text'] ?? ($coding['display'] ?? null),
            ], static fn ($v): bool => $v !== null);
        }

        $vitals = [];

        foreach ($serviceRequest['supportingInfo'] ?? [] as $reference) {
            $observation = $this->resolve($reference['reference'] ?? null);

            if ($observation === null || ($observation['resourceType'] ?? null) !== 'Observation') {
                continue;
            }

            $quantity = $observation['valueQuantity'] ?? null;

            $vitals[] = array_filter([
                'name' => $observation['code']['text'] ?? null,
                'code' => $observation['code']['coding'][0]['code'] ?? null,
                'value' => $quantity['value'] ?? ($observation['valueString'] ?? null),
                'unit' => $quantity['unit'] ?? null,
                'taken_at' => $observation['effectiveDateTime'] ?? null,
            ], static fn ($v): bool => $v !== null);
        }

        $encounter = $this->resolve($serviceRequest['encounter']['reference'] ?? null);

        return array_filter([
            'reason_text' => $serviceRequest['reasonCode'][0]['text'] ?? null,
            'notes' => $serviceRequest['note'][0]['text'] ?? null,
            'encounter' => $encounter === null ? null : array_filter([
                'class' => $encounter['class']['code'] ?? null,
                'status' => $encounter['status'] ?? null,
                'start' => $encounter['period']['start'] ?? null,
                'end' => $encounter['period']['end'] ?? null,
            ], static fn ($v): bool => $v !== null),
            'diagnoses' => $diagnoses,
            'vitals' => $vitals,
        ], static fn ($v): bool => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $serviceRequest
     * @return array<string, mixed>
     */
    private function request(array $serviceRequest, bool $isTelemedicine): array
    {
        $categories = array_flip(array_map(
            static fn (array $c): string => $c['code'],
            Constants::REFERRAL_CATEGORY,
        ));

        $serviceTypes = array_flip(array_map(
            static fn (array $c): string => $c['code'],
            Constants::SERVICE_TYPE,
        ));

        $common = [
            'priority' => $serviceRequest['priority'] ?? null,
            'specialty' => $serviceRequest['performerType']['text'] ?? null,
        ];

        if ($isTelemedicine) {
            // The modality survives only inside the code text we wrote on the way out.
            preg_match('/\(([^)]+)\)/', (string) ($serviceRequest['code']['text'] ?? ''), $m);

            return array_filter($common + [
                'modality' => $m[1] ?? null,
                'preferred_windows' => isset($serviceRequest['occurrenceDateTime'])
                    ? [['start' => $serviceRequest['occurrenceDateTime']]]
                    : null,
            ], static fn ($v): bool => $v !== null);
        }

        return array_filter($common + [
            'category' => $categories[$serviceRequest['category'][0]['coding'][0]['code'] ?? ''] ?? null,
            'service_type' => $serviceTypes[$serviceRequest['reasonCode'][0]['coding'][0]['code'] ?? ''] ?? null,
            'service_requested' => $serviceRequest['code']['text'] ?? null,
        ], static fn ($v): bool => $v !== null);
    }

    // -------------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function index(array $bundle): void
    {
        $this->byUrl = [];

        foreach ($bundle['entry'] ?? [] as $entry) {
            if (isset($entry['fullUrl'], $entry['resource'])) {
                $this->byUrl[(string) $entry['fullUrl']] = $entry['resource'];
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(?string $reference): ?array
    {
        return $reference === null ? null : ($this->byUrl[$reference] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstOfType(string $resourceType): ?array
    {
        foreach ($this->byUrl as $resource) {
            if (($resource['resourceType'] ?? null) === $resourceType) {
                return $resource;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $task */
    private function taskMessageId(?array $task): ?string
    {
        $system = (string) config('fhir.message_identifier_system');

        foreach ($task['identifier'] ?? [] as $identifier) {
            if (($identifier['system'] ?? null) === $system) {
                return (string) $identifier['value'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $resource */
    private function identifierValue(?array $resource, string $system): ?string
    {
        foreach ($resource['identifier'] ?? [] as $identifier) {
            if (($identifier['system'] ?? null) === $system) {
                return (string) $identifier['value'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $resource */
    private function telecom(?array $resource, string $system): ?string
    {
        foreach ($resource['telecom'] ?? [] as $point) {
            if (($point['system'] ?? null) === $system) {
                return (string) $point['value'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $role */
    private function roleKey(?array $role): ?string
    {
        $code = $role['code'][0]['coding'][0]['code'] ?? null;

        if ($code === null) {
            return null;
        }

        foreach (Constants::PRACTITIONER_ROLE as $key => $coding) {
            if ($coding['code'] === $code) {
                return $key;
            }
        }

        return null;
    }
}
