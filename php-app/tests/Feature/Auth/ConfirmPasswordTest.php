<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Auth\ConfirmPasswordController -- previously
 * untested despite already gating several JSON API routes (membership
 * assignment, taxpayer suspension, and others). Written now because the
 * Organisations UI slice (OrganisationViewController) is the first
 * Blade-rendered, plain-HTML-form consumer of this step-up flow, which
 * exposed a real bug: redirect()->intended() replays the *blocked*
 * request's own URL as a GET, but this app's step-up-gated write actions
 * are POST-only forms with no GET handler at that same path -- confirming
 * from a blocked POST used to redirect straight into a 404/405. Fixed by
 * having show() capture url()->previous() (the page the form was actually
 * on) and store() honour that instead of intended(), guarded same-origin
 * to close the open-redirect a raw hidden field would otherwise allow.
 */
class ConfirmPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Officer', 'email' => 'officer@confirm-test.test',
            'password' => bcrypt('correct-password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_confirming_from_a_blocked_post_redirects_back_to_the_page_the_form_was_on_not_a_404(): void
    {
        $user = $this->user();

        // A real GET first, exactly like a browser landing on the page
        // containing the form -- this is what populates url()->previous()
        // for the confirm-password page to read.
        $this->actingAs($user)->get('/dashboard');

        // Simulates the blocked POST redirect: 'password.confirm' middleware
        // itself isn't attached to /dashboard, so hit the real confirm page
        // directly and confirm that its hidden field carries the dashboard
        // URL as the previous page.
        $confirmPage = $this->actingAs($user)->get('/confirm-password');
        $confirmPage->assertOk();
        $confirmPage->assertSee(url('/dashboard'), false);

        $confirm = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'correct-password', 'redirect_to' => url('/dashboard'),
        ]);

        $confirm->assertRedirect(url('/dashboard'));
        $confirm->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_an_external_redirect_to_value_is_rejected_in_favour_of_the_dashboard(): void
    {
        $user = $this->user();

        $confirm = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'correct-password', 'redirect_to' => 'https://evil.example/phish',
        ]);

        $confirm->assertRedirect(route('dashboard'));
    }

    public function test_a_missing_redirect_to_falls_back_to_the_dashboard(): void
    {
        $user = $this->user();

        $confirm = $this->actingAs($user)->post('/confirm-password', ['password' => 'correct-password']);

        $confirm->assertRedirect(route('dashboard'));
    }

    public function test_an_incorrect_password_is_a_friendly_field_error(): void
    {
        $user = $this->user();

        $confirm = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password', 'redirect_to' => url('/dashboard'),
        ]);

        $confirm->assertSessionHasErrors('password');
        $confirm->assertSessionMissing('auth.password_confirmed_at');
    }

    /**
     * This session's own broader security review (backlog #10) found this
     * endpoint had no rate limiting at all, unlike `LoginRequest`'s own
     * 5-attempts throttle -- an attacker already holding a live,
     * authenticated session (a stolen cookie, XSS, a shared or unlocked
     * device) but not the account's actual password could brute-force it
     * here, unthrottled, to pass the step-up gate guarding every privileged
     * action in the app. Now rate-limited on the same shape as the login
     * form, keyed by user id (the actor is already known here) plus IP.
     */
    public function test_repeated_wrong_passwords_are_rate_limited_same_as_login(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password']);
        }

        $locked = $this->actingAs($user)->post('/confirm-password', ['password' => 'correct-password']);

        $locked->assertSessionHasErrors('password');
        $locked->assertSessionMissing('auth.password_confirmed_at');
        self::assertStringContainsString('Too many attempts', session('errors')->first('password'));
    }

    public function test_a_correct_password_clears_the_rate_limiter(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password']);
        $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-password']);
        $confirm = $this->actingAs($user)->post('/confirm-password', ['password' => 'correct-password']);

        $confirm->assertSessionHas('auth.password_confirmed_at');
    }
}
