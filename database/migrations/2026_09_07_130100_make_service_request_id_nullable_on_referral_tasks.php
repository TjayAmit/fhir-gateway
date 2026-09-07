<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inbound referral has no ServiceRequest id of ours.
 *
 * The column was written when only the outbound flow existed, where we create the
 * ServiceRequest and the receiver hands its id back. A referral arriving *from* another
 * facility is the mirror: the ServiceRequest lives in their system, and what we get back from
 * ours is a native record id, tracked in `resource_identity_map` instead.
 *
 * Found by the first inbound test, which is what tests are for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_tasks', function (Blueprint $table) {
            $table->string('fhir_service_request_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('referral_tasks', function (Blueprint $table) {
            $table->string('fhir_service_request_id', 64)->nullable(false)->change();
        });
    }
};
