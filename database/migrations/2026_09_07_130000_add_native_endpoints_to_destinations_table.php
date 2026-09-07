<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the gateway reaches back into a native system (docs/native-contract.md).
 *
 * `endpoint_url` is where we send FHIR *to* a facility. These two are the reverse direction:
 * where we hand a received referral to one of our own systems, and where we ask it whether it
 * knows a patient. Both speak the intake contract, not FHIR — a native system never parses a
 * Bundle in either direction.
 *
 * Null means not wired up, and the gateway refuses rather than pretending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('inbound_url', 500)->nullable()->after('endpoint_url');
            $table->string('patient_search_url', 500)->nullable()->after('inbound_url');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn(['inbound_url', 'patient_search_url']);
        });
    }
};
