<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps the FHIR identity we expose to the HCPN onto the native record it came from.
 *
 * Per decision D5 this mapping is permanent: the HCPN addresses resources by FHIR id,
 * the native systems use their own keys, and that correspondence must stay stable for
 * the life of the gateway. Nothing clinical is stored here — keys and identifiers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_identity_map', function (Blueprint $table) {
            $table->id();

            // The identity we expose over FHIR.
            $table->string('fhir_resource_type', 64);   // Patient, ServiceRequest, Task, Organization...
            $table->string('fhir_id', 64);

            // Where it actually lives.
            $table->string('source_system', 64);        // which native system
            $table->string('native_table', 128)->nullable();
            $table->string('native_id', 128);           // string: native PKs are not always integers

            // National identifier, when the resource has one. Lets us resolve an
            // inbound referral by PhilSys/PhilHealth/NHFR without a native lookup.
            $table->string('identifier_system', 255)->nullable();
            $table->string('identifier_value', 128)->nullable();

            $table->timestamps();

            $table->unique(['fhir_resource_type', 'fhir_id']);
            $table->unique(['source_system', 'native_table', 'native_id', 'fhir_resource_type'], 'rim_native_unique');
            $table->index(['identifier_system', 'identifier_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_identity_map');
    }
};
