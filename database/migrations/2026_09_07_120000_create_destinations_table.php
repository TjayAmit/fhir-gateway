<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The destination registry — the list senders choose from.
 *
 * This is the table that lets the referral and telemedicine systems stay ignorant of who
 * they are talking to. They know an opaque `hcpn_id`; endpoints, credentials and transport
 * live here and nowhere else, so onboarding a facility is a row, not a release.
 *
 * Rows are seeded by hand from the government facility list. There is no national endpoint
 * directory yet; when one appears it replaces the seeding, not this schema.
 *
 * Directory data only — organisations, not patients. Nothing here is clinical, so it sits
 * comfortably inside the no-persistence rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinations', function (Blueprint $table) {
            $table->id();

            // The opaque public id. Senders reference this and nothing else about routing.
            $table->string('hcpn_id', 64)->unique();

            $table->string('display_name', 255);
            $table->string('city', 128)->nullable();

            // Identity in the national schemes.
            $table->string('nhfr_code', 64);
            $table->string('hcpn_code', 64)->nullable();
            $table->string('phone', 64)->nullable();

            // Transport. Null endpoint means the facility is in the directory but cannot yet
            // receive anything — the common case today. Such a row is listed as
            // not-deliverable rather than silently accepting referrals it can never forward.
            $table->string('endpoint_url', 500)->nullable();
            $table->string('auth_type', 32)->nullable();      // none | bearer | mtls
            $table->string('auth_credential_key', 128)->nullable(); // config/secret lookup key, never the secret

            // Which intake request_types this destination will take.
            $table->json('accepts')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['active', 'nhfr_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinations');
    }
};
