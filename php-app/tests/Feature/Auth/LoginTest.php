<?php

namespace Tests\Feature\Auth;

use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Requests\Auth\LoginRequest. The suspended-account cases
 * are the fix for red team finding RT-003
 * (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): a suspended-account-specific
 * error message was only reachable once Auth::attempt() had already
 * confirmed the submitted password was correct, letting anyone who already
 * held valid credentials for a suspended account confirm that fact (a wrong
 * password against the same suspended account fell into the generic
 * "credentials do not match" branch instead, making the two cases
 * distinguishable -- CWE-203, observable discrepancy).
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(string $status = 'ACTIVE'): User
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-LOGIN-0001', 'tin' => 'TIN-LOGIN-0001',
            'legal_name' => 'Login Test Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'login-taxpayer@test.test',
        ]);

        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Login Test Owner', 'email' => 'login-owner@test.test',
            'password' => bcrypt('correct-password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => $status,
        ]);
    }

    public function test_a_correct_password_against_a_suspended_account_shows_the_same_generic_message_as_a_wrong_password(): void
    {
        $this->makeUser('SUSPENDED');

        $response = $this->post('/login', ['email' => 'login-owner@test.test', 'password' => 'correct-password']);

        $response->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_a_wrong_password_against_the_same_suspended_account_shows_the_identical_message(): void
    {
        $this->makeUser('SUSPENDED');

        $correctPasswordAttempt = $this->post('/login', ['email' => 'login-owner@test.test', 'password' => 'correct-password']);
        $wrongPasswordAttempt = $this->post('/login', ['email' => 'login-owner@test.test', 'password' => 'totally-wrong']);

        // The two denial paths (correct password + suspended, vs wrong
        // password) are now indistinguishable from the login response --
        // this is the direct regression test for RT-003.
        $this->assertSame(
            $correctPasswordAttempt->getSession()->get('errors')->first('email'),
            $wrongPasswordAttempt->getSession()->get('errors')->first('email'),
        );
    }

    public function test_a_correct_password_against_an_active_account_logs_in_successfully(): void
    {
        $user = $this->makeUser('ACTIVE');

        $response = $this->post('/login', ['email' => 'login-owner@test.test', 'password' => 'correct-password']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_suspended_account_still_cannot_log_in_despite_the_message_change(): void
    {
        // Confirms the fix is purely cosmetic (message text only) -- the
        // actual deny decision for a suspended account is unchanged.
        $this->makeUser('SUSPENDED');

        $this->post('/login', ['email' => 'login-owner@test.test', 'password' => 'correct-password']);

        $this->assertGuest();
    }
}
