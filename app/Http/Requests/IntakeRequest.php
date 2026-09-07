<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Fhir\Constants;
use App\Http\Middleware\AuthenticateGatewayClient;
use App\Models\Destination;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validates one submission against the intake contract (docs/intake-contract.md).
 *
 * Everything a native system sends is checked here, before a single FHIR resource is built.
 * A failure comes back as an OperationOutcome so the sender gets an answer in the same
 * vocabulary the rest of the exchange uses.
 */
class IntakeRequest extends FormRequest
{
    /** ISO 8601 with an explicit UTC offset. A bare local timestamp is rejected, not assumed. */
    private const ISO_8601_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // --- Envelope -------------------------------------------------------------
            'message_id' => ['required', 'uuid'],
            'sent_at' => ['required', 'string', 'regex:'.self::ISO_8601_OFFSET],
            'request_type' => ['required', Rule::in(['referral', 'telemedicine'])],

            // --- Source ---------------------------------------------------------------
            'source' => ['required', 'array'],
            'source.system' => ['required', Rule::in(['referral', 'telemedicine'])],
            'source.record_id' => ['required', 'string', 'max:128'],
            'source.facility_code' => ['required', 'string', 'max:64'],
            'source.facility_name' => ['nullable', 'string', 'max:255'],
            'source.practitioner' => ['required', 'array'],
            'source.practitioner.family_name' => ['required', 'string', 'max:128'],
            'source.practitioner.given_names' => ['required', 'array', 'min:1'],
            'source.practitioner.given_names.*' => ['required', 'string', 'max:128'],
            'source.practitioner.prc_license' => ['required', 'string', 'max:32'],
            'source.practitioner.role' => ['nullable', Rule::in(array_keys(Constants::PRACTITIONER_ROLE))],
            'source.practitioner.phone' => ['nullable', 'string', 'max:64'],
            'source.practitioner.email' => ['nullable', 'email', 'max:255'],

            // --- Destination ----------------------------------------------------------
            'destination' => ['required', 'array'],
            'destination.hcpn_id' => ['required', 'string', Rule::exists(Destination::class, 'hcpn_id')],
            'destination.department' => ['nullable', 'string', 'max:128'],

            // --- Patient --------------------------------------------------------------
            'patient' => ['required', 'array'],
            'patient.identifiers' => ['required', 'array', 'min:1'],
            'patient.identifiers.*.type' => ['required', Rule::in(['philsys', 'philhealth', 'local'])],
            'patient.identifiers.*.value' => ['required', 'string', 'max:64'],
            'patient.family_name' => ['required', 'string', 'max:128'],
            'patient.given_names' => ['required', 'array', 'min:1'],
            'patient.given_names.*' => ['required', 'string', 'max:128'],
            'patient.suffix' => ['nullable', 'string', 'max:32'],
            'patient.birth_date' => ['required', 'date_format:Y-m-d'],
            'patient.sex' => ['required', Rule::in(['male', 'female', 'other', 'unknown'])],
            'patient.mobile' => ['nullable', 'string', 'max:64'],
            'patient.address' => ['nullable', 'array'],
            'patient.address.line' => ['nullable', 'string', 'max:255'],
            'patient.address.barangay_psgc' => ['nullable', 'string', 'max:16'],
            'patient.address.city_psgc' => ['nullable', 'string', 'max:16'],
            'patient.address.province_psgc' => ['nullable', 'string', 'max:16'],
            'patient.address.region_psgc' => ['nullable', 'string', 'max:16'],
            'patient.address.postal_code' => ['nullable', 'string', 'max:16'],

            // --- Clinical -------------------------------------------------------------
            'clinical' => ['required', 'array'],
            'clinical.reason_text' => ['required', 'string', 'max:2000'],
            'clinical.notes' => ['nullable', 'string', 'max:5000'],
            'clinical.diagnoses' => ['nullable', 'array'],
            'clinical.diagnoses.*.system' => ['nullable', Rule::in(['icd10', 'snomed', 'text'])],
            'clinical.diagnoses.*.code' => ['nullable', 'string', 'max:32'],
            'clinical.diagnoses.*.text' => ['required', 'string', 'max:500'],
            'clinical.vitals' => ['nullable', 'array'],
            'clinical.vitals.*.name' => ['required', 'string', 'max:128'],
            'clinical.vitals.*.value' => ['required'],
            'clinical.vitals.*.unit' => ['nullable', 'string', 'max:32'],
            'clinical.vitals.*.code' => ['nullable', 'string', 'max:32'],
            'clinical.vitals.*.system' => ['nullable', 'string', 'max:255'],
            'clinical.vitals.*.taken_at' => ['nullable', 'string', 'regex:'.self::ISO_8601_OFFSET],
            'clinical.encounter' => ['nullable', 'array'],
            'clinical.encounter.class' => ['nullable', Rule::in(['AMB', 'EMER', 'IMP', 'HH', 'VR'])],
            'clinical.encounter.status' => ['nullable', Rule::in(['planned', 'arrived', 'in-progress', 'finished'])],
            'clinical.encounter.start' => ['nullable', 'string', 'regex:'.self::ISO_8601_OFFSET],
            'clinical.encounter.end' => ['nullable', 'string', 'regex:'.self::ISO_8601_OFFSET],

            // --- Referral (required when request_type = referral) ---------------------
            'referral' => ['required_if:request_type,referral', 'array'],
            'referral.category' => [
                'required_if:request_type,referral',
                Rule::in(array_keys(Constants::REFERRAL_CATEGORY)),
            ],
            'referral.service_type' => [
                'required_if:request_type,referral',
                Rule::in(array_keys(Constants::SERVICE_TYPE)),
            ],
            'referral.priority' => ['nullable', Rule::in(Constants::PRIORITIES)],
            'referral.service_requested' => ['nullable', 'string', 'max:255'],
            'referral.specialty' => ['nullable', 'string', 'max:128'],

            // --- Telemedicine (required when request_type = telemedicine) -------------
            'telemedicine' => ['required_if:request_type,telemedicine', 'array'],
            'telemedicine.modality' => [
                'required_if:request_type,telemedicine',
                Rule::in(['video', 'audio', 'chat', 'store-and-forward']),
            ],
            'telemedicine.priority' => ['nullable', Rule::in(Constants::PRIORITIES)],
            'telemedicine.specialty' => ['nullable', 'string', 'max:128'],
            'telemedicine.preferred_windows' => ['nullable', 'array'],
            'telemedicine.preferred_windows.*.start' => ['required', 'string', 'regex:'.self::ISO_8601_OFFSET],
            'telemedicine.preferred_windows.*.end' => ['required', 'string', 'regex:'.self::ISO_8601_OFFSET],

            // --- Attachments ----------------------------------------------------------
            'attachments' => ['nullable', 'array'],
            'attachments.*.title' => ['required', 'string', 'max:255'],
            'attachments.*.content_type' => ['required', 'string', 'max:128'],
            'attachments.*.url' => ['required', 'url', 'max:1000'],
            'attachments.*.expires_at' => ['nullable', 'string', 'regex:'.self::ISO_8601_OFFSET],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertNationalIdentifier($validator);
            $this->assertDestinationCanReceive($validator);
            $this->assertClientOwnsTheSystem($validator);
        });
    }

    /**
     * A local MRN alone is not enough — it means nothing to a receiving facility.
     *
     * Policy is that triage records both PhilSys and PhilHealth. We enforce "at least one"
     * rather than "both" because the process for patients who have neither yet, newborns
     * above all, is not settled. Tightening this to require both is a one-line change here.
     */
    private function assertNationalIdentifier(Validator $validator): void
    {
        $types = array_column((array) $this->input('patient.identifiers', []), 'type');

        if (! array_intersect($types, ['philsys', 'philhealth'])) {
            $validator->errors()->add(
                'patient.identifiers',
                'At least one PhilSys or PhilHealth identifier is required. A local identifier alone cannot be resolved by the receiving facility.',
            );
        }
    }

    /**
     * Refuse a destination we cannot actually reach.
     *
     * The registry lists every facility from the government list, but most have no endpoint
     * yet. Accepting a referral for one of those would queue it forever and tell the sender
     * nothing, so it is rejected at the door instead.
     */
    private function assertDestinationCanReceive(Validator $validator): void
    {
        $hcpnId = $this->input('destination.hcpn_id');

        if (! is_string($hcpnId) || $hcpnId === '') {
            return;
        }

        $destination = Destination::query()->where('hcpn_id', $hcpnId)->first();

        if ($destination === null) {
            return; // the exists rule already reported this
        }

        if (! $destination->isDeliverable()) {
            $validator->errors()->add(
                'destination.hcpn_id',
                "Destination [{$hcpnId}] is listed but cannot receive referrals yet: no delivery endpoint is configured.",
            );

            return;
        }

        $requestType = (string) $this->input('request_type');

        if (! $destination->acceptsRequestType($requestType)) {
            $validator->errors()->add(
                'destination.hcpn_id',
                "Destination [{$hcpnId}] does not accept {$requestType} requests.",
            );
        }
    }

    /**
     * A client may only submit as itself.
     *
     * `source.system` is in the body, so it is the caller's claim about who they are. The token
     * is what we actually know. Without this check the referral system could file referrals
     * attributed to telemedicine, and the audit trail would faithfully record the lie.
     */
    private function assertClientOwnsTheSystem(Validator $validator): void
    {
        $client = $this->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);

        if (! is_array($client)) {
            return; // unauthenticated requests never reach here
        }

        $claimed = (string) $this->input('source.system');

        if ($claimed !== '' && ! in_array($claimed, $client['systems'], true)) {
            $validator->errors()->add(
                'source.system',
                "This client may not submit as [{$claimed}].",
            );
        }
    }

    /**
     * Report failures as an OperationOutcome rather than Laravel's default error bag.
     */
    protected function failedValidation(Validator $validator): void
    {
        $issues = [];

        foreach ($validator->errors()->toArray() as $field => $messages) {
            foreach ($messages as $message) {
                $issues[] = [
                    'severity' => 'error',
                    'code' => 'invalid',
                    'diagnostics' => $message,
                    'expression' => [$field],
                ];
            }
        }

        throw new HttpResponseException(response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => $issues,
        ], 422));
    }
}
