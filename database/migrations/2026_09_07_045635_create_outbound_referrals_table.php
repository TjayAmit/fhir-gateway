<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox for referrals we send to the HCPN.
 *
 * Note what is NOT here: the referral payload. Decision D1 forbids persisting clinical
 * data, so a retry re-reads the native record and re-translates rather than replaying a
 * stored bundle. That also means a retry always sends current data — which is correct
 * for a referral that has not yet been accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_referrals', function (Blueprint $table) {
            $table->id();

            // What to rebuild the payload from.
            $table->string('source_system', 64);
            $table->string('native_id', 128);

            // Where it is going.
            $table->string('target_facility', 64);          // DOH NHFR code
            $table->string('target_endpoint', 500)->nullable();

            // pending | sending | delivered | failed
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            // Populated once the receiving end accepts the transaction.
            $table->string('fhir_task_id', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->unique(['source_system', 'native_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_referrals');
    }
};
