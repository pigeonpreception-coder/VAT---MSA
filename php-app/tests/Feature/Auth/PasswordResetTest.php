<?php

namespace Tests\Feature\Auth;

use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the self-service password-reset flow added for red team finding
 * RT-005 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md) -- previously this app
 * had no such flow at all. See ForgotPasswordRequest's and
 * ResetPasswordRequest's own doc comments for the account-enumeration
 * safety this flow deliberately preserves throughout.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(string $email = 'reset-owner@test.test'): User
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-RESET-0001', 'tin' => 'TIN-RESET-0001',
            'legal_name' => 'Reset Test Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'reset-taxpayer@test.test',
        ]);

        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Reset Test Owner', 'email' => $email,
            'password' => bcrypt('original-password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_forgot_password_page_is_reachable_from_the_login_page(): void
    {
        $this->get('/login')->assertSee(route('password.request'), false);
    }

    public function test_requesting_a_reset_link_for_a_real_email_sends_the_notification(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_requesting_a_reset_link_for_an_unregistered_email_shows_the_identical_confirmation_and_sends_nothing(): void
    {
        // Direct regression test for RT-005's account-enumeration safety:
        // both outcomes must be indistinguishable from the response alone.
        Notification::fake();
        $user = $this->makeUser();

        $realEmailResponse = $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::fake(); // reset the recorded-notifications ledger before the second attempt

        $fakeEmailResponse = $this->post('/forgot-password', ['email' => 'nobody-registered@test.test']);

        $this->assertSame(
            $realEmailResponse->getSession()->get('status'),
            $fakeEmailResponse->getSession()->get('status'),
        );
        Notification::assertNothingSent();
    }

    public function test_requesting_a_reset_link_is_rate_limited(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email]);
        }
        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many password reset attempts', $response->getSession()->get('errors')->first('email'));
    }

    public function test_the_full_reset_cycle_lets_the_user_log_in_with_the_new_password(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token, 'A reset token should have been captured from the sent notification.');

        $resetResponse = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewStrongPass1',
            'password_confirmation' => 'NewStrongPass1',
        ]);

        $resetResponse->assertRedirect('/login');
        $resetResponse->assertSessionHas('status');
        $this->assertTrue(Hash::check('NewStrongPass1', $user->fresh()->password));

        $loginResponse = $this->post('/login', ['email' => $user->email, 'password' => 'NewStrongPass1']);
        $loginResponse->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_expired_or_invalid_token_is_rejected_with_a_generic_message(): void
    {
        $user = $this->makeUser();

        $response = $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'NewStrongPass1',
            'password_confirmation' => 'NewStrongPass1',
        ]);

        $response->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired. Request a new one.']);
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password), 'The password must not have changed.');
    }

    public function test_a_used_token_cannot_be_replayed(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $first = $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'NewStrongPass1', 'password_confirmation' => 'NewStrongPass1',
        ]);
        $first->assertRedirect('/login');

        $replay = $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'AnotherStrongPass2', 'password_confirmation' => 'AnotherStrongPass2',
        ]);

        $replay->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewStrongPass1', $user->fresh()->password), 'The first reset must still be the one in effect.');
    }

    public function test_a_weak_new_password_is_rejected(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $response = $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'weak', 'password_confirmation' => 'weak',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_password_reset_pages(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get('/forgot-password')->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/reset-password/some-token')->assertRedirect('/dashboard');
    }
}
