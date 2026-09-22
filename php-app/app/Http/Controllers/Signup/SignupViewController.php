<?php

namespace App\Http\Controllers\Signup;

use App\Exceptions\RateLimitExceededException;
use App\Exceptions\RepositoryConflictException;
use App\Exceptions\SignupAuthorityRequiredException;
use App\Exceptions\SignupValidationException;
use App\Http\Controllers\Controller;
use App\Services\Signup\SignupService;
use App\Support\Security\RequestContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for SignupService, the actual public-facing equivalent of
 * source's JSON-only self-serve signup channel -- unlike every other
 * "Blade UI added despite source being JSON-API-only" precedent this
 * session, a public signup form genuinely is what a real anonymous
 * applicant would use; SignupController's stateless JSON endpoint (routes/
 * api.php) exists for a real external caller (an integration partner's
 * own site) with no browser session, matching PosInvoiceController's own
 * precedent for that shape.
 *
 * Lives in routes/web.php's `guest` middleware group alongside /login --
 * an already-authenticated user has no reason to apply for a new
 * commercial subscription through this channel. Reuses
 * Controller::formIdempotencyKey()/<x-idempotency-key/> exactly like
 * every other Blade write form in this app -- both work with no
 * dependency on an authenticated session, only a started one, which every
 * web request already has.
 *
 * `create()` now also passes `SignupService::listPublicPlans()` (ported
 * from lib/data/signup-repository.ts's listPublicSignupPlans, source's
 * own real public/unauthenticated read for exactly this page) to the
 * view -- previously the plan field was a free-text input defaulting to
 * 'PILOT_PROFESSIONAL', forcing a real applicant to already know the
 * exact plan code by heart rather than choosing from the currently-open
 * commercial plans by name/features, the same lookup submit() itself
 * validates a submitted plan_code against.
 */
class SignupViewController extends Controller
{
    public function __construct(private readonly SignupService $signup) {}

    public function create(): View
    {
        return view('signup.create', ['plans' => $this->signup->listPublicPlans()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = [
            'schema_version' => '1.0.0', 'applicant_name' => (string) $request->input('applicant_name'),
            'applicant_role' => (string) $request->input('applicant_role'), 'contact_email' => (string) $request->input('contact_email'),
            'country_code' => 'NA', 'plan_code' => (string) $request->input('plan_code'),
            'vat_number' => (string) $request->input('vat_number'), 'tin' => (string) $request->input('tin'),
            'company_registration_number' => $request->input('company_registration_number') ?: null,
            'legal_name' => (string) $request->input('legal_name'), 'trading_name' => $request->input('trading_name') ?: null,
            'taxpayer_type' => (string) $request->input('taxpayer_type'), 'return_frequency' => (string) $request->input('return_frequency'),
            'address' => (string) $request->input('address'),
            'company_system_administrator_attested' => $request->boolean('company_system_administrator_attested'),
            'terms_accepted' => $request->boolean('terms_accepted'),
            'privacy_notice_accepted' => $request->boolean('privacy_notice_accepted'),
        ];

        try {
            // Not sourceToken()/deviceId() -- see
            // RequestContext::unauthenticatedRequestIp()'s own doc
            // comment (same reasoning as SignupController's JSON twin).
            $ip = RequestContext::unauthenticatedRequestIp($request);
            $accepted = $this->signup->submit($payload, $ip, $ip, $this->formIdempotencyKey($request));
        } catch (SignupValidationException|SignupAuthorityRequiredException|RepositoryConflictException|RateLimitExceededException $e) {
            return back()->withErrors(['signup' => $e->getMessage()])->withInput();
        }

        return redirect()->route('signup.create')->with('accepted', $accepted);
    }
}
