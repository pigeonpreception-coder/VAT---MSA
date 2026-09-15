<?php

namespace Tests\Concerns;

use App\Models\MfaTotpCredential;
use App\Models\StepUpEvent;
use App\Support\Access\Totp;
use Illuminate\Support\Str;

/**
 * 2026-09-15 TOTP cutover: replaces every test's former
 * `->withSession(['auth.password_confirmed_at' => time()])` shortcut
 * (previously read by the old ConfirmPasswordController/StepUp session
 * check) with the real freshness source, App\Services\Identity\
 * MfaService::hasFreshStepUp -- a `step_up_events` row, not a session key.
 * Directly inserting the row (rather than driving the full enrol->verify->
 * confirm choreography MfaTest/MfaViewTest already cover on their own)
 * keeps every one of these ~200 call sites a one-line precondition, the
 * same spirit as the old shortcut.
 *
 * Both methods act on whichever user `actingAs()` most recently
 * authenticated in this test process -- actingAs() sets the guard's user
 * on the same application instance the test runs in, so it is readable
 * back here without needing the user passed in again, mirroring how
 * `withSession()` itself needed no user argument either.
 */
trait InteractsWithStepUp
{
    /** The acting user has a fresh, currently-valid step-up confirmation -- the direct replacement for the old "confirmed just now" shortcut. */
    protected function withFreshStepUp(): static
    {
        $this->seedStepUpEvent(now()->subMinute(), now()->addMinutes(4));

        return $this;
    }

    /** The acting user's step-up confirmation has expired -- the direct replacement for the old "confirmed a long time ago" shortcut. */
    protected function withStaleStepUp(): static
    {
        $this->seedStepUpEvent(now()->subHours(2), now()->subHours(1));

        return $this;
    }

    private function seedStepUpEvent($verifiedAt, $expiresAt): void
    {
        $user = $this->app['auth']->guard()->user();

        MfaTotpCredential::updateOrCreate(
            ['user_id' => $user->id],
            ['secret_base32' => Totp::generateSecret(), 'status' => 'ACTIVE', 'last_used_counter' => 0, 'created_at' => now(), 'verified_at' => now()],
        );
        StepUpEvent::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'method' => 'TOTP',
            'verified_at' => $verifiedAt, 'expires_at' => $expiresAt,
        ]);
    }
}
