<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for every HCPN request (decision D7).
 *
 * This table intentionally records which patient was looked up and by whom — that is
 * the purpose of an audit trail, and is the one place patient identifiers are stored
 * on purpose. It is a legal obligation once we broker patient data, not a debug log.
 *
 * Append-only by convention: never update or delete rows outside of a retention job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            $table->timestamp('occurred_at')->index();

            // Who asked. Resolved from the authenticated client, never from the request body.
            $table->string('actor_client_id', 128)->nullable();
            $table->string('actor_facility', 64)->nullable();     // DOH NHFR code
            $table->string('actor_ip', 45)->nullable();

            // What they did.
            $table->string('action', 32);                          // search | read | create | update
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();

            // Search parameters as received, after scope injection.
            // Contains patient identifiers by design — see the class docblock.
            $table->json('query')->nullable();
            $table->string('scope_filter', 255)->nullable();

            // Outcome.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('result_count')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['actor_facility', 'occurred_at']);
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
