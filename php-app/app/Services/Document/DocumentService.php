<?php

namespace App\Services\Document;

use App\Domain\Document\DocumentValidator;
use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\DocumentMetadata;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/platform-repository.ts's document commands --
 * Module 22's own Documents & Records slice, now closed out in full:
 * uploadDocument/completeDocumentScan (pulled forward in Phase 11 as the
 * real prerequisite for `DOCUMENT`-sourced audit evidence citation) plus
 * this Phase 13 pass's own supersedeDocument/getDocumentVersionHistory/
 * setDocumentRetentionHold/downloadDocument. Still deliberately NOT
 * ported: the platform/developer-portal snapshots, offline sync,
 * integrations, and report exports -- genuinely separate sub-modules of
 * `platform-repository.ts`, not this file's own document-specific slice.
 *
 * `env.DOCUMENTS` (a Cloudflare R2 bucket binding in the source) has no
 * Laravel equivalent in this migration by design (no Cloudflare
 * dependency of any kind) -- substituted with Laravel's own `local`
 * filesystem disk, storing under the identical `quarantine/{organisation_id}/
 * {document_id}/{file_name}` object-key shape the source uses, so the
 * eventual real object-storage adapter (S3/R2-compatible, whenever a
 * later phase needs one) is a disk-driver swap, not a key-shape rewrite.
 *
 * The magic-byte content-sniffing check (`matchesDeclaredType`) is a
 * genuine, named security control in the source (SECURITY_GAP_ASSESSMENT.md
 * item #7 -- never trust a client-declared MIME type alone) and is ported
 * verbatim, not simplified into "trust Laravel's own fileinfo detection
 * instead" -- a different mechanism achieving a similar-sounding but not
 * identical guarantee.
 *
 * `App\Models\DocumentMetadata` is deliberately excluded from
 * `App\Models\Concerns\BelongsToOrganisation` (see
 * docs/MIGRATION_MATRIX.md's "Organisation-scope trait retrofit"): the
 * read paths below (`versionHistory`/`download`) fetch a document
 * unscoped by id, then call `OrganisationResolver::resolve()` to
 * distinguish a genuine 404 from a 403 outside the actor's authorised
 * scope -- the same fetch-then-check shape that disqualified
 * `AuditCase`/`RefundClaim`/etc.
 */
class DocumentService
{
    private const ALLOWED_DOCUMENT_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    private const OWNER_DOMAINS = ['EXPENSE', 'IMPORT', 'AUDIT_CASE', 'VAT_ADJUSTMENT', 'REFUND', 'BANK_IMPORT'];

    private const CLASSIFICATIONS = ['INTERNAL', 'CONFIDENTIAL', 'TAX_CONFIDENTIAL', 'RESTRICTED'];

    public function __construct(private readonly OrganisationResolver $organisations) {}

    /**
     * Owner_domain/owner_resource_id/classification are validated inline
     * here, not via App\Domain\Document\DocumentValidator -- the source
     * itself validates them inline in this exact function (a single-message
     * PlatformResourceError, not the list-shaped PlatformValidationError
     * that file's own exported normalizers use), so this mirrors that
     * placement rather than inventing a normalizer the source doesn't have.
     *
     * @return array<string, mixed>
     */
    public function upload(UploadedFile $file, array $input, User $actor, ?string $requestedOrganisationId, string $correlationId): array
    {
        $scope = $this->organisations->resolve($actor, $requestedOrganisationId);

        $ownerDomain = mb_strtoupper(trim((string) ($input['owner_domain'] ?? '')));
        if (! in_array($ownerDomain, self::OWNER_DOMAINS, true)) {
            throw new PlatformResourceException('Owner domain is not supported.');
        }
        $ownerResourceId = trim((string) ($input['owner_resource_id'] ?? ''));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/', $ownerResourceId)) {
            throw new PlatformResourceException('Owner resource id is invalid.');
        }
        $classification = mb_strtoupper(trim((string) ($input['classification'] ?? '')));
        if (! in_array($classification, self::CLASSIFICATIONS, true)) {
            throw new PlatformResourceException('Document classification is invalid.');
        }

        ['bytes' => $bytes, 'checksum' => $checksum, 'fileName' => $fileName] = $this->validateAndHashFile($file);

        $id = (string) Str::uuid();
        $objectKey = "quarantine/{$scope->id}/{$id}/{$fileName}";
        $now = now();

        Storage::disk('local')->put($objectKey, $bytes);
        try {
            DB::transaction(function () use ($id, $scope, $ownerDomain, $ownerResourceId, $objectKey, $fileName, $file, $checksum, $classification, $actor, $now, $correlationId) {
                DocumentMetadata::create([
                    'id' => $id, 'organisation_id' => $scope->id, 'owner_domain' => $ownerDomain, 'owner_resource_id' => $ownerResourceId,
                    'object_key' => $objectKey, 'file_name' => $fileName, 'content_type' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(),
                    'checksum_sha256' => $checksum, 'classification' => $classification, 'scan_status' => 'PENDING_EXTERNAL_SCANNER',
                    'status' => 'QUARANTINED', 'uploaded_by' => $actor->id, 'uploaded_at' => $now, 'retained_until' => null,
                    'legal_hold' => false, 'scanned_by' => null, 'scanned_at' => null, 'supersedes_document_id' => null,
                ]);
                CommandLedger::outbox('DOCUMENT', $id, 'DocumentQuarantined', $scope->taxpayer_id, ['document_id' => $id, 'owner_domain' => $ownerDomain, 'owner_resource_id' => $ownerResourceId, 'correlation_id' => $correlationId], $now);
                AuditService::append($actor, 'DOCUMENT_QUARANTINED', 'DOCUMENT', $id, ['organisationId' => $scope->id, 'ownerDomain' => $ownerDomain, 'ownerResourceId' => $ownerResourceId, 'checksum' => $checksum, 'correlationId' => $correlationId], $now);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($objectKey);
            throw $e;
        }

        return $this->present(DocumentMetadata::findOrFail($id));
    }

    /**
     * The missing scan-completion path: CLEAN -> ACTIVE (available,
     * citable as evidence), INFECTED -> REJECTED (permanently blocked --
     * the object is never deleted, so a rejected upload remains its own
     * evidence). Restricted to a national/platform-admin role: this
     * represents an external system's callback, not a taxpayer self-
     * service action.
     *
     * @return array<string, mixed>
     */
    public function completeScan(string $documentId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national platform role may record a document scan result.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DocumentValidator::scanResult($payload);

        $requestHash = CommandLedger::requestHash(['document_id' => $documentId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'COMPLETE_DOCUMENT_SCAN', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(DocumentMetadata::findOrFail($prior));
        }

        $document = DocumentMetadata::find($documentId);
        if (! $document) {
            throw new PlatformResourceException('Document was not found.', 404);
        }
        if ($document->status !== 'QUARANTINED') {
            throw new RepositoryConflictException('Document has already been scanned.');
        }

        $now = now();
        $newStatus = $input['outcome'] === 'CLEAN' ? 'ACTIVE' : 'REJECTED';
        $taxpayerId = $document->organisation->taxpayer_id;
        DB::transaction(function () use ($documentId, $newStatus, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $document, $taxpayerId) {
            DocumentMetadata::where('id', $documentId)->where('status', 'QUARANTINED')
                ->update(['status' => $newStatus, 'scan_status' => $input['outcome'], 'scanned_by' => $actor->id, 'scanned_at' => $now]);
            CommandLedger::record($actor->id, 'COMPLETE_DOCUMENT_SCAN', $idempotencyKey, $requestHash, 'DOCUMENT', $documentId, $now);
            CommandLedger::outbox('DOCUMENT', $documentId, $input['outcome'] === 'CLEAN' ? 'DocumentScanClean' : 'DocumentScanInfected', $taxpayerId, ['document_id' => $documentId, 'outcome' => $input['outcome'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, "DOCUMENT_SCAN_{$input['outcome']}", 'DOCUMENT', $documentId, ['organisationId' => $document->organisation_id, 'outcome' => $input['outcome'], 'notes' => $input['notes'], 'correlationId' => $correlationId], $now);
        });

        return $this->present(DocumentMetadata::findOrFail($documentId));
    }

    /**
     * Module 6 Phase A SupersedeDocument: the Version concept the playbook
     * names. `document_metadata` rows are themselves the version chain
     * (the same pattern `audit_evidence.previous_version_id` and
     * `vat_return_versions.parent_version_id` already use in this schema)
     * rather than a separate Version table. Only a CLEAN, ACTIVE document
     * can be superseded -- you correct a live document, not one still
     * awaiting scan or already rejected/superseded (that status check also
     * makes the chain a strict linked list: a given document can only ever
     * be superseded once, since a second attempt finds status no longer
     * ACTIVE). The superseded row and its stored object are never deleted,
     * only flipped to `status='SUPERSEDED'` -- the same "never destroy,
     * always append" posture used everywhere else this codebase models a
     * correction.
     *
     * @return array<string, mixed>
     */
    public function supersede(string $documentId, UploadedFile $file, User $actor, ?string $requestedOrganisationId, string $correlationId): array
    {
        $original = DocumentMetadata::find($documentId);
        if (! $original) {
            throw new PlatformResourceException('Document was not found.', 404);
        }
        $scope = $this->organisations->resolve($actor, $requestedOrganisationId ?? $original->organisation_id);
        if ($original->status !== 'ACTIVE') {
            throw new RepositoryConflictException('Only a clean, active document can be superseded.');
        }

        ['bytes' => $bytes, 'checksum' => $checksum, 'fileName' => $fileName] = $this->validateAndHashFile($file);

        $id = (string) Str::uuid();
        $objectKey = "quarantine/{$scope->id}/{$id}/{$fileName}";
        $now = now();

        Storage::disk('local')->put($objectKey, $bytes);
        try {
            DB::transaction(function () use ($id, $scope, $original, $documentId, $objectKey, $fileName, $file, $checksum, $actor, $now, $correlationId) {
                DocumentMetadata::create([
                    'id' => $id, 'organisation_id' => $scope->id, 'owner_domain' => $original->owner_domain, 'owner_resource_id' => $original->owner_resource_id,
                    'object_key' => $objectKey, 'file_name' => $fileName, 'content_type' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(),
                    'checksum_sha256' => $checksum, 'classification' => $original->classification, 'scan_status' => 'PENDING_EXTERNAL_SCANNER',
                    'status' => 'QUARANTINED', 'uploaded_by' => $actor->id, 'uploaded_at' => $now, 'retained_until' => null,
                    'legal_hold' => false, 'scanned_by' => null, 'scanned_at' => null, 'supersedes_document_id' => $documentId,
                ]);
                DocumentMetadata::where('id', $documentId)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED']);
                CommandLedger::outbox('DOCUMENT', $id, 'DocumentSuperseded', $scope->taxpayer_id, ['document_id' => $id, 'supersedes_document_id' => $documentId, 'correlation_id' => $correlationId], $now);
                AuditService::append($actor, 'DOCUMENT_SUPERSEDED', 'DOCUMENT', $id, ['organisationId' => $scope->id, 'supersedesDocumentId' => $documentId, 'checksum' => $checksum, 'correlationId' => $correlationId], $now);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($objectKey);
            throw $e;
        }

        return $this->present(DocumentMetadata::findOrFail($id));
    }

    /**
     * Module 6 Phase A GetDocumentVersionHistory: walks the
     * `supersedes_document_id` chain in both directions so calling it on
     * any version returns the complete history, oldest first.
     *
     * @return array<string, mixed>
     */
    public function versionHistory(string $documentId, User $actor): array
    {
        $anchor = DocumentMetadata::find($documentId);
        if (! $anchor) {
            throw new PlatformResourceException('Document was not found.', 404);
        }
        $this->organisations->resolve($actor, $anchor->organisation_id);

        $chain = [$anchor];
        $cursor = $anchor;
        while ($cursor->supersedes_document_id) {
            $prev = DocumentMetadata::find($cursor->supersedes_document_id);
            if (! $prev) {
                break;
            }
            array_unshift($chain, $prev);
            $cursor = $prev;
        }
        $cursor = $anchor;
        while (true) {
            $next = DocumentMetadata::where('supersedes_document_id', $cursor->id)->first();
            if (! $next) {
                break;
            }
            $chain[] = $next;
            $cursor = $next;
        }

        return ['document_id' => $documentId, 'versions' => array_map(fn (DocumentMetadata $d) => $this->present($d), $chain)];
    }

    /**
     * Module 6 Phase B ApplyRetentionHold/ReleaseRetentionHold: until now
     * the only way to toggle `document_metadata.legal_hold` was
     * indirectly, via Module 4's SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD
     * evidence-custody action (`AuditCaseService::recordEvidenceCustodyEvent`),
     * and only once a document had already been cited as audit evidence. A
     * document a compliance officer wants preserved before -- or without
     * -- ever being cited as evidence had no hold path at all. This is
     * that direct path, and cascades the other way from Module 4's own
     * cascade: if this document is already cited by one or more
     * `audit_evidence` rows, their `legal_hold` flag is kept in sync too,
     * so the two hold paths can never disagree about the same underlying
     * document.
     *
     * The playbook's stronger claim -- "ApplyHold checked by every
     * deletion/retention job repo-wide" -- is not built: a full-repo audit
     * before this phase confirmed no deletion or retention/purge job
     * exists anywhere in this codebase to wire it into. There is nothing
     * to enforce against yet, so nothing is stubbed against it, matching
     * the source's own comment on this exact function verbatim.
     *
     * @return array<string, mixed>
     */
    public function setRetentionHold(string $documentId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national platform role may set a document retention hold.');
        }
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = DocumentValidator::hold($payload);

        $requestHash = CommandLedger::requestHash(['document_id' => $documentId, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'SET_DOCUMENT_RETENTION_HOLD', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present(DocumentMetadata::findOrFail($prior));
        }

        $document = DocumentMetadata::find($documentId);
        if (! $document) {
            throw new PlatformResourceException('Document was not found.', 404);
        }

        $now = now();
        $holdValue = $input['action'] === 'APPLY';
        $taxpayerId = $document->organisation->taxpayer_id;
        DB::transaction(function () use ($documentId, $input, $holdValue, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $document, $taxpayerId) {
            if ($input['action'] === 'APPLY') {
                // COALESCE semantics: an explicit retained_until overrides,
                // otherwise the document's own current value is written
                // back unchanged, matching the source's own
                // `COALESCE(?, retained_until)` exactly.
                DocumentMetadata::where('id', $documentId)->update([
                    'legal_hold' => true,
                    'retained_until' => $input['retained_until'] ?? $document->retained_until,
                ]);
            } else {
                DocumentMetadata::where('id', $documentId)->update(['legal_hold' => false]);
            }
            DB::table('audit_evidence')->where('document_id', $documentId)->update(['legal_hold' => $holdValue]);
            CommandLedger::record($actor->id, 'SET_DOCUMENT_RETENTION_HOLD', $idempotencyKey, $requestHash, 'DOCUMENT', $documentId, $now);
            CommandLedger::outbox('DOCUMENT', $documentId, $input['action'] === 'APPLY' ? 'DocumentRetentionHoldApplied' : 'DocumentRetentionHoldReleased', $taxpayerId, ['document_id' => $documentId, 'action' => $input['action'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, "DOCUMENT_RETENTION_HOLD_{$input['action']}", 'DOCUMENT', $documentId, ['organisationId' => $document->organisation_id, 'action' => $input['action'], 'notes' => $input['notes'], 'retainedUntil' => $input['retained_until'], 'correlationId' => $correlationId], $now);
        });

        return $this->present(DocumentMetadata::findOrFail($documentId));
    }

    /**
     * Module 6 Phase B AuthorizedDownload: until now there was no way to
     * retrieve an uploaded document at all. Refuses anything not
     * currently ACTIVE or SUPERSEDED (both passed a clean scan at some
     * point; QUARANTINED hasn't been scanned yet and REJECTED is
     * permanently blocked) -- the "no download before clean scan"
     * principle. Access is logged via the same audit-events hash chain as
     * every other command in this file, not a bespoke access-log table --
     * "access logging" is the requirement, not a new entity.
     *
     * @return array{bytes: string, contentType: string, fileName: string}
     */
    public function download(string $documentId, User $actor, string $correlationId): array
    {
        $document = DocumentMetadata::find($documentId);
        if (! $document) {
            throw new PlatformResourceException('Document was not found.', 404);
        }
        $this->organisations->resolve($actor, $document->organisation_id);
        if (! in_array($document->status, ['ACTIVE', 'SUPERSEDED'], true)) {
            throw new RepositoryConflictException('The document is not available for download in its current state.');
        }
        if (! Storage::disk('local')->exists($document->object_key)) {
            throw new PlatformResourceException('The document object could not be located in storage.', 404);
        }
        $bytes = Storage::disk('local')->get($document->object_key);

        $now = now();
        AuditService::append($actor, 'DOCUMENT_DOWNLOADED', 'DOCUMENT', $documentId, ['organisationId' => $document->organisation_id, 'correlationId' => $correlationId], $now);

        return ['bytes' => $bytes, 'contentType' => $document->content_type, 'fileName' => $document->file_name];
    }

    /**
     * Ported from uploadDocument's own validateAndHashFile/
     * matchesDeclaredType -- MIME allow-list, a 1-byte-to-10-MiB size
     * bound, then a real magic-byte check of the actual file content
     * against its client-declared type (never trust the declared type
     * alone), and finally the SHA-256 checksum every downstream evidence
     * citation and integrity re-verification derives from.
     *
     * @return array{bytes: string, checksum: string, fileName: string}
     */
    private function validateAndHashFile(UploadedFile $file): array
    {
        $mimeType = (string) $file->getClientMimeType();
        if (! in_array($mimeType, self::ALLOWED_DOCUMENT_TYPES, true)) {
            throw new PlatformResourceException('File type is not allowed for governed evidence.', 415);
        }
        $size = $file->getSize();
        if ($size === false || $size < 1 || $size > 10_485_760) {
            throw new PlatformResourceException('Evidence files must contain 1 byte to 10 MiB.', 413);
        }
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new PlatformResourceException('The uploaded file could not be read.', 415);
        }
        if (! $this->matchesDeclaredType($mimeType, $bytes)) {
            throw new PlatformResourceException('File content does not match its declared type.', 415);
        }
        $checksum = hash('sha256', $bytes);
        $fileName = DocumentValidator::safeFileName((string) $file->getClientOriginalName());

        return ['bytes' => $bytes, 'checksum' => $checksum, 'fileName' => $fileName];
    }

    /**
     * PDF/PNG/JPEG/XLSX (a ZIP container) all have real magic numbers;
     * CSV is plain text with no signature to check, so it instead fails a
     * NUL-byte sniff test in a bounded prefix -- the standard heuristic
     * for "this is binary data, not text" (mirrors what `file`(1)/git
     * use). This is content-sniffing, not malware scanning --
     * completeScan()'s human-asserted verdict remains the only scanning
     * this codebase has, matching the source's own comment on this exact
     * function verbatim.
     */
    private function matchesDeclaredType(string $mimeType, string $bytes): bool
    {
        $prefix = array_values(unpack('C*', substr($bytes, 0, 8)) ?: []);

        return match ($mimeType) {
            'application/pdf' => ($prefix[0] ?? null) === 0x25 && ($prefix[1] ?? null) === 0x50 && ($prefix[2] ?? null) === 0x44 && ($prefix[3] ?? null) === 0x46,
            'image/png' => ($prefix[0] ?? null) === 0x89 && ($prefix[1] ?? null) === 0x50 && ($prefix[2] ?? null) === 0x4e && ($prefix[3] ?? null) === 0x47
                && ($prefix[4] ?? null) === 0x0d && ($prefix[5] ?? null) === 0x0a && ($prefix[6] ?? null) === 0x1a && ($prefix[7] ?? null) === 0x0a,
            'image/jpeg' => ($prefix[0] ?? null) === 0xff && ($prefix[1] ?? null) === 0xd8 && ($prefix[2] ?? null) === 0xff,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ($prefix[0] ?? null) === 0x50 && ($prefix[1] ?? null) === 0x4b && in_array($prefix[2] ?? null, [0x03, 0x05, 0x07], true),
            'text/csv' => ! str_contains(substr($bytes, 0, 512), "\0"),
            default => false,
        };
    }

    /**
     * Extended (Phase 13) to include `legal_hold`/`retained_until`/
     * `supersedes_document_id`/`uploaded_by`/`scanned_by` -- the new
     * supersede/version-history/retention-hold commands all need them in
     * their responses, matching the source's own `SELECT *` shape more
     * fully than Phase 11's original narrower curation needed to.
     *
     * @return array<string, mixed>
     */
    private function present(DocumentMetadata $document): array
    {
        return [
            'id' => $document->id, 'organisation_id' => $document->organisation_id, 'owner_domain' => $document->owner_domain,
            'owner_resource_id' => $document->owner_resource_id, 'file_name' => $document->file_name, 'content_type' => $document->content_type,
            'size_bytes' => $document->size_bytes, 'checksum_sha256' => $document->checksum_sha256, 'classification' => $document->classification,
            'scan_status' => $document->scan_status, 'status' => $document->status, 'uploaded_by' => $document->uploaded_by,
            'uploaded_at' => optional($document->uploaded_at)->toISOString(), 'retained_until' => optional($document->retained_until)->toISOString(),
            'legal_hold' => (bool) $document->legal_hold, 'scanned_by' => $document->scanned_by,
            'scanned_at' => optional($document->scanned_at)->toISOString(), 'supersedes_document_id' => $document->supersedes_document_id,
        ];
    }
}
