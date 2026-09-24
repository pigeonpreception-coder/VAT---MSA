<?php

namespace App\Http\Controllers\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\SubmitRegistrationRequest;
use App\Services\Identity\RegistrationService;
use App\Services\Signup\SignupService;
use App\Support\Access\TaxpayerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
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
}
