<?php

namespace App\Http\Controllers\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\DecideRegistrationRequest;
use App\Http\Requests\Identity\SubmitRegistrationRequest;
use App\Services\Identity\RegistrationService;
use App\Services\Signup\SignupService;
use App\Support\Access\TaxpayerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ported from the source's own app/registrations/page.tsx -- "Taxpayer
 * registration intake" -- and its app/registrations/new/RegistrationForm.tsx
 * sibling. Two independent read sections: the self-serve signup queue
 * (national-scope actors only, SignupService::listSelfServeSignupApplications
 * gates on TaxpayerScope::isNational internally same as source's own
 * isNationalScope(user)) and the controlled registration-application
 * register (RegistrationService::list(), already used by the JSON API and
 * IdentityFoundationSnapshotService). store() reuses
 * RegistrationService::submit() directly -- the same write
 * RegistrationApplicationController::store() exposes as JSON -- as a
 * classic form POST, matching this migration's own BusinessPartyViewController
 * precedent for pairing a JSON controller with a Blade-form one.
 * storeDecision() is RT-003's fix (2026-10-24): RegistrationService::decide()
 * (approve/reject, materializing a Taxpayer/Organisation on approval) had
 * no Blade path at all, only its JSON sibling
 * RegistrationApplicationController::decision() -- no NamRA officer could
 * ever onboard a taxpayer through the product itself.
 */
class RegistrationsViewController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly SignupService $signup,
    ) {}

    public function index(): View
    {
        $this->authorize('permission', 'registrations:read');

        $user = request()->user();

        return view('registrations.index', [
            'applications' => $this->registrations->list($user),
            'selfServeApplications' => $this->signup->listSelfServeSignupApplications($user),
            'isNationalScope' => TaxpayerScope::isNational($user),
            'canDecide' => $user->hasAppPermission('registrations:approve'),
        ]);
    }

    public function store(SubmitRegistrationRequest $request): RedirectResponse
    {
        $this->authorize('permission', 'registrations:submit');

        try {
            $this->registrations->submit($request->normalized(), $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('registrations.index')->with('status', 'Application accepted and held for ITAS/NamRA verification. No taxpayer or organisation is created until verification and approval complete.');
    }

    public function storeDecision(DecideRegistrationRequest $request, string $registration): RedirectResponse
    {
        $this->authorize('permission', 'registrations:approve');
        // Step-up gated: routes/web.php applies 'step-up' to this route,
        // matching the JSON API's own
        // /api/v1/registration-applications/{id}/decision POST.

        try {
            $decision = $this->registrations->decide(
                $request->user(),
                $registration,
                $request->validated('decision'),
                $request->validated('reason'),
                (string) Str::uuid(),
            );
        } catch (RepositoryConflictException|ValidationException $e) {
            return back()->withErrors($e instanceof ValidationException ? $e->errors() : ['form' => $e->getMessage()]);
        }

        $verb = $decision['status'] === 'APPROVED' ? 'approved -- a taxpayer and organisation have been created' : 'rejected';
        return redirect()->route('registrations.index')->with('status', "Registration application {$verb}.");
    }
}
