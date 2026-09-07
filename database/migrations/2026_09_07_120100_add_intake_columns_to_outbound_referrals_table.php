<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the intake envelope onto the outbox row.
 *
 * `message_id` is the sender's idempotency key. It is unique, which is what makes a replayed
 * submission return the original result instead of opening a second referral — the guarantee
 * the intake contract makes.
 *
 * It also replaces (source_system, native_id) as the unique key. That constraint assumed one
 * referral per native record, which is wrong: a patient rejected by one facility gets referred
 * again from the same record, and that is a new submission, not a duplicate. The pair stays
 * indexed because a retry still re-reads the native record to rebuild the payload.
 *
 * Still no payload column: a retry re-translates from source (D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_referrals', function (Blueprint $table) {
            $table->uuid('message_id')->nullable()->after('id');
            $table->string('request_type', 16)->nullable()->after('native_id');
            $table->string('destination_hcpn_id', 64)->nullable()->after('request_type');
            $table->string('fhir_service_request_id', 64)->nullable()->after('fhir_task_id');
        });

        Schema::table('outbound_referrals', function (Blueprint $table) {
            $table->dropUnique(['source_system', 'native_id']);
        });

        Schema::table('outbound_referrals', function (Blueprint $table) {
            $table->unique('message_id');
            $table->index('destination_hcpn_id');
            $table->index(['source_system', 'native_id']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_referrals', function (Blueprint $table) {
            $table->dropUnique(['message_id']);
            $table->dropIndex(['destination_hcpn_id']);
            $table->dropIndex(['source_system', 'native_id']);
        });

        Schema::table('outbound_referrals', function (Blueprint $table) {
            $table->unique(['source_system', 'native_id']);
            $table->dropColumn([
                'message_id',
                'request_type',
                'destination_hcpn_id',
                'fhir_service_request_id',
            ]);
        });
    }
};
