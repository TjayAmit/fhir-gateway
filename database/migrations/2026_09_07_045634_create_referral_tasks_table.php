<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral workflow state — the eReferral Task lifecycle.
 *
 * Deliberately carries NO clinical content (decision D1). The clinical picture stays
 * in the native systems and is translated on demand; this table tracks only where a
 * referral is in the workflow.
 *
 * business_status carries the eReferral workflow code:
 *   received | accepted | rejected | referred-onward | capacity-full
 *   (https://fhir.doh.gov.ph/pheref/CodeSystem/ereferral-workflow)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_tasks', function (Blueprint $table) {
            $table->id();

            $table->string('fhir_task_id', 64)->unique();
            $table->string('fhir_service_request_id', 64)->index();

            // 'inbound'  = HCPN referred a patient to us
            // 'outbound' = we referred a patient out
            $table->string('direction', 16);

            // FHIR R4 TaskStatus.
            $table->string('status', 32);
            // eReferral workflow sub-state.
            $table->string('business_status', 32)->nullable();
            $table->string('status_reason', 255)->nullable();

            // Facilities, by DOH NHFR code.
            $table->string('requester_facility', 64)->nullable();
            $table->string('performer_facility', 64)->nullable();

            // Subject, by our exposed FHIR id (resolve via resource_identity_map).
            $table->string('patient_fhir_id', 64)->nullable()->index();

            // Onward-referral chaining: the ServiceRequest this one replaces.
            $table->string('replaces_service_request_id', 64)->nullable();

            $table->timestamp('authored_on')->nullable();
            $table->timestamp('last_modified')->nullable();

            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index(['performer_facility', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_tasks');
    }
};
