<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a taxpayer's own private Point-of-Sale system against a
 * real credential issued by App\Services\Integration\PosApiClientService
 * -- the one stateless, non-session entry point in this application (see
 * routes/api.php's own doc comment for why the existing api/v1/** JSON
 * mirror under routes/web.php can't serve this instead).
 *
 * Expects `Authorization: Bearer <client_key>.<client_secret>`. The
 * secret is checked with Hash::check against api_clients.
 * credential_reference (a bcrypt hash -- never the plaintext, which
 * exists only at issuance time); the client_key alone is not a valid
 * credential.
 *
 * On success this sets the resolved developer account owner as the
 * request's authenticated user (Auth::setUser) purely for this
 * request's lifecycle -- no session is started, matching the stateless
 * `api` middleware group this route runs under -- so downstream code
 * (App\Http\Controllers\Integration\PosInvoiceController,
 * App\Services\Invoice\InvoiceService::submit()'s own TaxpayerScope check)
 * can use $request->user() exactly as the session-authenticated JSON
 * mirror already does.
 */
class AuthenticatePosApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Provide a POS credential as "Authorization: Bearer <client_key>.<client_secret>".');
        }

        $token = mb_substr($header, 7);
        [$clientKey, $secret] = array_pad(explode('.', $token, 2), 2, '');
        if ($clientKey === '' || $secret === '') {
            return $this->unauthorized('The bearer token must be in the form <client_key>.<client_secret>.');
        }

        $client = ApiClient::where('client_key', $clientKey)->first();
        if (! $client || $client->status !== 'ACTIVE') {
            return $this->unauthorized('This POS credential is unknown or inactive.');
        }
        if ($client->expires_at && $client->expires_at->isPast()) {
            return $this->unauthorized('This POS credential has expired.');
        }
        if (! Hash::check($secret, $client->credential_reference)) {
            return $this->unauthorized('This POS credential is unknown or inactive.');
        }
        if (! in_array('invoices:submit', $client->scopeList(), true)) {
            return response()->json(['code' => 'FORBIDDEN', 'message' => 'This POS credential is not scoped for invoice submission.'], Response::HTTP_FORBIDDEN);
        }

        $client->loadMissing('developerAccount.ownerUser');
        $actor = $client->developerAccount?->ownerUser;
        if (! $actor) {
            return response()->json(['code' => 'CREDENTIAL_MISCONFIGURED', 'message' => 'This POS credential has no resolvable account owner.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        Auth::setUser($actor);
        $request->attributes->set('posApiClient', $client);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['code' => 'UNAUTHORIZED', 'message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
