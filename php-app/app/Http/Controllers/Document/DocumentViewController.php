<?php

namespace App\Http\Controllers\Document;

use App\Exceptions\PlatformResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\DocumentMetadata;
use App\Services\Document\DocumentService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/documents/page.tsx +
 * DocumentUploadForm.tsx -- the evidence register and the real, governed
 * upload-to-quarantine form. Reuses
 * App\Services\Document\DocumentService::upload directly (the same method
 * App\Http\Controllers\Document\DocumentController::store already serves
 * at POST /api/v1/documents), including its real MIME allow-list, size
 * bound, magic-byte content-sniffing and SHA-256 checksum -- none of that
 * is re-implemented here.
 *
 * The register query is a direct `App\Models\DocumentMetadata` read
 * scoped to the actor's own organisation, matching the exact `$documents`
 * sub-query already inside
 * App\Services\Platform\PlatformSnapshotService::getSnapshot (the
 * source's own combined `getPlatformSnapshot`, which that one query is
 * extracted from) rather than pulling in that whole dozen-table
 * aggregate -- the same "simple real query, not the source's fixed list"
 * precedent already established by
 * App\Http\Controllers\Business\InventoryController::indexMovements and
 * every other slice that made the same call.
 *
 * The source's own page has no UI at all for scan-decision, supersede,
 * retention-hold or download (confirmed by reading app/documents/page.tsx
 * in full -- its own subtitle even says "Downloads are unavailable while
 * malware scanning is not configured"), so none of those are built here
 * either -- not a gap, a faithful match of the source's own scope for
 * this specific screen. All four remain reachable at their existing JSON
 * routes for whichever future admin/national-scope screen needs them.
 */
class DocumentViewController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'documents:read');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        $documents = DocumentMetadata::where('organisation_id', $organisation->id)->orderByDesc('uploaded_at')->limit(100)->get();

        return view('documents.index', [
            'documents' => $documents,
            'canUpload' => $user->hasAppPermission('documents:upload'),
            'defaultOwnerDomain' => $request->query('owner_domain') === 'EXPENSE' ? 'EXPENSE' : '',
            'defaultOwnerResourceId' => $request->query('owner_domain') === 'EXPENSE' ? (string) $request->query('owner_resource_id', '') : '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'documents:upload');
        $file = $request->file('file');
        $prefill = ['owner_domain' => $request->input('owner_domain'), 'owner_resource_id' => $request->input('owner_resource_id')];

        if (! $file || ! $file->isValid()) {
            return redirect()->route('documents.index', $prefill)->withErrors(['file' => "Multipart field 'file' is required."]);
        }

        $input = [
            'owner_domain' => (string) $request->input('owner_domain', ''),
            'owner_resource_id' => (string) $request->input('owner_resource_id', ''),
            'classification' => (string) $request->input('classification', 'TAX_CONFIDENTIAL'),
        ];

        try {
            $document = $this->documents->upload($file, $input, $request->user(), null, (string) Str::uuid());
        } catch (PlatformResourceException|RepositoryConflictException $e) {
            return redirect()->route('documents.index', $prefill)->withErrors(['file' => $e->getMessage()])->withInput();
        }

        return redirect()->route('documents.index')->with('status', "Evidence quarantined. Reference {$document['id']}, SHA-256 {$document['checksum_sha256']}.");
    }
}
