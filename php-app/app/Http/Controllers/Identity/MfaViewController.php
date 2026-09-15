<?php

namespace App\Http\Controllers\Identity;

use App\Exceptions\IdentityValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\MfaTotpCredential;
use App\Services\Identity\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Blade UI for MfaService, alongside the JSON API surface
 * MfaController already exposes -- see InvoiceViewController's own doc
 * comment for why this app keeps a dedicated Blade-rendering controller
 * next to each JSON one. No corresponding page.tsx exists in the source
 * (the original built the MFA domain/API/tests but never a settings
 * page for it) -- this page is a genuine addition, not a port, needed to
 * make the infrastructure actually self-serviceable rather than
 * curl-only.
 *
 * The freshly-generated secret and otpauth URI are flashed into the
 * session by store() and shown exactly once, matching the JSON API's own
 * "never returned again" contract (see MfaService::enrollTotp's own doc
 * comment) -- a page reload after that loses them, at which point the
 * only way forward is to restart enrolment (still allowed while
 * PENDING_VERIFICATION, per enrollTotp's own upsert).
 */
class MfaViewController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'identity:read');
        $actor = $request->user();
        $credential = MfaTotpCredential::find($actor->id);
        $status = $this->mfa->getMfaStatus($actor->id);

        return view('security.mfa.index', [
            'credentialStatus' => $credential?->status,
            'enrolled' => $status['enrolled'],
            'hasRecentStepUp' => $status['hasRecentStepUp'],
            'freshSecret' => session('mfa_fresh_secret'),
            'freshOtpauthUri' => session('mfa_fresh_otpauth_uri'),
        ]);
    }

    public function enroll(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'identity:read');

        try {
            $enrollment = $this->mfa->enrollTotp($request->user(), (string) Str::uuid());
        } catch (RepositoryConflictException $e) {
            return redirect()->route('security.mfa')->withErrors(['mfa' => $e->getMessage()]);
        }

        return redirect()->route('security.mfa')
            ->with('mfa_fresh_secret', $enrollment['secret'])
            ->with('mfa_fresh_otpauth_uri', $enrollment['otpauthUri'])
            ->with('status', 'Scan the QR code or enter the secret in your authenticator app, then enter a code below to finish enrolling.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'identity:read');

        try {
            $this->mfa->verifyTotpEnrollment($request->user(), $request->only('code'), (string) Str::uuid());
        } catch (IdentityValidationException $e) {
            return redirect()->route('security.mfa')->withErrors(['code' => $e->errors()[0]['message']]);
        } catch (RepositoryConflictException $e) {
            return redirect()->route('security.mfa')->withErrors(['mfa' => $e->getMessage()]);
        }

        return redirect()->route('security.mfa')->with('status', 'Multi-factor authentication is now enabled on your account.');
    }

    public function stepUp(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'identity:read');

        try {
            $this->mfa->confirmStepUp($request->user(), $request->only('code'), (string) Str::uuid());
        } catch (IdentityValidationException $e) {
            return redirect()->route('security.mfa')->withErrors(['step_up_code' => $e->errors()[0]['message']]);
        }

        return redirect()->route('security.mfa')->with('status', 'Step-up confirmed. It stays fresh for the next few minutes.');
    }
}
