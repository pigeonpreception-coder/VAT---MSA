<?php

namespace App\Services\Integration;

use App\Exceptions\BusinessValidationException;
use App\Models\ApiClient;
use App\Models\CredentialRef;
use App\Models\DeveloperAccount;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * User's own explicit request: a taxpayer's own private Point-of-Sale
 * system must be able to push invoices into VAT-MSA in real time through
 * "their API shared" with this system -- and, having weighed it against
 * the honest-but-permanently-unavailable ITAS/E-Tariff pattern, chose to
 * have this genuinely work today rather than stay a stub, since (unlike a
 * real external government system) this integration's other end is
 * entirely this application's own to build.
 *
 * This is the first real command reaching the Developer Portal's
 * `developer_accounts`/`api_clients`/`credential_refs` tables (Module 10),
 * which until now were schema-only, read-only display data
 * (PlatformSnapshotService::developerPortalSnapshot()). A taxpayer with
 * `integrations:manage` on an organisation holding the SELLER capability
 * (only a seller issues invoices; a buyer's side of a sale is populated
 * automatically the moment the seller's push names their VAT number --
 * see App\Services\Invoice\InvoiceService::submit()'s own MATCHED-status
 * handling) can issue a credential here, which
 * App\Http\Middleware\AuthenticatePosApiClient then authenticates real
 * pushes against.
 */
class PosApiClientService
{
    private const SCOPE_INVOICE_SUBMIT = 'invoices:submit';

    /**
     * @return array{client_key: string, client_secret: string, api_client_id: string}
     */
    public function issue(Organisation $organisation, User $actor, string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new BusinessValidationException([
                ['code' => 'NAME_INVALID', 'path' => 'name', 'message' => 'Give this POS credential a name between 1 and 100 characters.'],
            ]);
        }
        if (! $organisation->capabilities()->where('capability', 'SELLER')->where('status', 'ACTIVE')->exists()) {
            throw new BusinessValidationException([
                ['code' => 'SELLER_CAPABILITY_REQUIRED', 'path' => 'organisation_id', 'message' => 'Only an organisation with an active seller capability can issue a POS invoice-submission credential.'],
            ]);
        }

        $now = now();
        $clientKey = 'pos_'.Str::random(32);
        $clientSecret = Str::random(48);

        $result = DB::transaction(function () use ($organisation, $actor, $name, $now, $clientKey, $clientSecret) {
            $developerAccount = DeveloperAccount::firstOrCreate(
                ['organisation_id' => $organisation->id, 'owner_user_id' => $actor->id],
                ['id' => (string) Str::uuid(), 'display_name' => $organisation->legal_name, 'status' => 'ACTIVE', 'created_at' => $now],
            );

            $apiClientId = (string) Str::uuid();
            $credentialHash = Hash::make($clientSecret);
            ApiClient::create([
                'id' => $apiClientId, 'organisation_id' => $organisation->id, 'developer_account_id' => $developerAccount->id,
                'name' => $name, 'client_key' => $clientKey, 'scopes' => json_encode([self::SCOPE_INVOICE_SUBMIT]),
                'credential_reference' => $credentialHash, 'status' => 'ACTIVE', 'rate_limit_profile' => 'STANDARD',
                'created_by' => $actor->id, 'created_at' => $now,
            ]);
            CredentialRef::create([
                'id' => (string) Str::uuid(), 'api_client_id' => $apiClientId, 'credential_reference' => $credentialHash,
                'status' => 'ACTIVE', 'issued_by' => $actor->id, 'issued_at' => $now,
            ]);

            AuditService::append($actor, 'POS_API_CLIENT_ISSUED', 'API_CLIENT', $apiClientId, [
                'organisationId' => $organisation->id, 'name' => $name, 'clientKey' => $clientKey,
            ], $now);

            return $apiClientId;
        });

        return ['client_key' => $clientKey, 'client_secret' => $clientSecret, 'api_client_id' => $result];
    }

    /** @return Collection<int, ApiClient> */
    public function list(Organisation $organisation): Collection
    {
        return ApiClient::where('organisation_id', $organisation->id)->orderByDesc('created_at')->get();
    }

    public function revoke(Organisation $organisation, string $apiClientId, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new BusinessValidationException([
                ['code' => 'REASON_INVALID', 'path' => 'reason', 'message' => 'Give a 5 to 240 character reason for revoking this credential.'],
            ]);
        }
        $client = ApiClient::where('id', $apiClientId)->where('organisation_id', $organisation->id)->first();
        if (! $client) {
            throw new BusinessValidationException([
                ['code' => 'API_CLIENT_NOT_FOUND', 'path' => 'api_client_id', 'message' => 'This credential does not belong to your organisation.'],
            ]);
        }
        if ($client->status === 'REVOKED') {
            return;
        }

        $now = now();
        DB::transaction(function () use ($client, $actor, $reason, $now) {
            $client->update(['status' => 'REVOKED']);
            CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')
                ->update(['status' => 'REVOKED', 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revocation_reason' => $reason]);

            AuditService::append($actor, 'POS_API_CLIENT_REVOKED', 'API_CLIENT', $client->id, [
                'organisationId' => $client->organisation_id, 'reason' => $reason,
            ], $now);
        });
    }
}
