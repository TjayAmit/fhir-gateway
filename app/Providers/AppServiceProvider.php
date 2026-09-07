<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateGatewayClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
    }

    /**
     * Per-client request ceilings on the gateway API.
     *
     * Keyed on the authenticated client rather than the IP: our systems sit behind shared
     * egress, so an IP-keyed limit would have them throttling each other. An unauthenticated
     * request falls back to the IP, which is all we know about it.
     *
     * The search limit is the tightest of the three. An unthrottled identifier search is an
     * enumeration tool: try national IDs until one returns a patient.
     */
    protected function configureRateLimits(): void
    {
        foreach (['intake', 'read', 'search'] as $bucket) {
            RateLimiter::for('fhir-'.$bucket, function (Request $request) use ($bucket): Limit {
                $client = $request->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);
                $key = is_array($client) ? $client['id'] : $request->ip();

                return Limit::perMinute((int) config("fhir.rate_limits.{$bucket}"))
                    ->by($bucket.':'.$key)
                    ->response(fn (): \Illuminate\Http\JsonResponse => response()->json([
                        'resourceType' => 'OperationOutcome',
                        'issue' => [[
                            'severity' => 'error',
                            'code' => 'throttled',
                            'diagnostics' => 'Too many requests. Slow down and retry.',
                        ]],
                    ], 429));
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
