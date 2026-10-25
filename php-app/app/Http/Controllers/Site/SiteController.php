<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\PublicPlanCatalogue;
use App\Support\Site\VerificationCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public marketing site -- the pages a visitor sees before signing in
 * or applying for access. Every action is an unauthenticated read; the
 * only one that does anything is verify(), which turns a pasted code or
 * link into a redirect to the existing /verify/{token} certificate page.
 *
 * Copy on these pages is deliberately limited to what the platform does
 * today, and it carries the same pilot caveats the product itself shows
 * (see resources/views/verify/show.blade.php): certificates issued during
 * the pilot are pilot certificates, and connectors to external authority
 * systems stay disabled until a technical contract is confirmed.
 */
class SiteController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        // An already-signed-in user has no use for the marketing page and
        // used to land on the dashboard from `/`; keep that.
        if ($request->user() !== null) {
            return redirect()->route('dashboard');
        }

        return view('site.home', ['plans' => PublicPlanCatalogue::open()]);
    }

    public function product(): View
    {
        return view('site.product');
    }

    public function trust(): View
    {
        return view('site.trust');
    }

    public function plans(): View
    {
        return view('site.plans', ['plans' => PublicPlanCatalogue::open()]);
    }

    public function verify(Request $request): View|RedirectResponse
    {
        $submitted = $request->query('code');
        if (! is_string($submitted) || trim($submitted) === '') {
            return view('site.verify', ['code' => '', 'error' => null]);
        }

        $token = VerificationCode::extract($submitted);
        if ($token === null) {
            return view('site.verify', [
                'code' => mb_substr($submitted, 0, 300),
                'error' => 'That does not look like a verification code. Paste the code or the full link printed on the invoice.',
            ]);
        }

        return redirect()->route('verify.show', ['token' => $token]);
    }

    public function sitemap(): Response
    {
        $urls = [
            route('site.home'), route('site.product'), route('site.trust'),
            route('site.plans'), route('site.verify'), route('signup.create'),
        ];

        // The XML declaration is prepended here, not written in the template:
        // with short_open_tag on, PHP reads a literal "<?xml" in a Blade file
        // as PHP code, and Blade's tokenizer leaves it uncompiled.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('site.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
