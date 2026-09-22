<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\ClaimInvitationRequest;
use App\Services\Identity\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from app/api/v1/invitations/claim/route.ts -- Module 1 Identity
 * ProvisionUser, claim half. See App\Services\Identity\
 * UserInvitationService::claim()'s own doc comment for why this is a
 * Blade, password-setting flow rather than a JSON route trusting a
 * platform-asserted identity the way source's own claimInvitation did.
 * `guest`-gated (see routes/web.php) -- the whole point is that the
 * claimant has no app_users row, and therefore no session, yet.
 */
class InvitationClaimController extends Controller
{
    public function __construct(private readonly UserInvitationService $invitations) {}

    public function create(Request $request): View
    {
        return view('auth.claim-invitation', ['token' => $request->query('token', '')]);
    }

    public function store(ClaimInvitationRequest $request): RedirectResponse
    {
        $this->invitations->claim($request->validated('token'), $request->validated('name'), $request->validated('password'), (string) Str::uuid());

        return redirect()->route('login')->with('status', 'Your account has been created. You can now sign in.');
    }
}
