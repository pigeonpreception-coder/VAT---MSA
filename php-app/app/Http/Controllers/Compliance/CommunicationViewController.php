<?php

namespace App\Http\Controllers\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Exceptions\ComplianceValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Compliance\CommunicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Blade UI for CommunicationService (Module 6 Phase C: notices,
 * responses, closure), alongside the JSON API surface CommunicationController
 * already exposes -- see InvoiceViewController's own doc comment for why
 * this app keeps a dedicated Blade-rendering controller next to each JSON
 * one.
 *
 * This is the real gap behind the red-team finding that prompted this
 * controller. The finding's own claim that "no file in resources/views
 * references communicat* and no GET route renders a communications page"
 * is factually wrong -- compliance/overview.blade.php
 * (ComplianceOverviewViewController) already renders a full read-only
 * preview of every thread -- but that page is permanently read-only by
 * its own design (see its doc comment: ComplianceSnapshotService has no
 * write side at all), so there was never a way to actually send a notice,
 * respond, or close a thread anywhere in the UI. This controller is that
 * missing write surface. compliance/overview.blade.php's Communications
 * card is updated in the same change to a count+link, the same treatment
 * every other domain on that page already gets, now that this page exists
 * to link to.
 *
 * index()/show()/storeResponse() deliberately gate on the same broad
 * compliance:read the JSON siblings do (CommunicationController::inbox/
 * show/respond) -- the real respond permission (communications:manage OR
 * communications:respond) is enforced inside CommunicationService::respond
 * itself, the exact layered-permission pattern the JSON controller already
 * uses. One side effect inherited unchanged from that JSON gate, not
 * introduced here: TAXPAYER_ACCOUNTANT holds communications:respond but
 * not compliance:read (Permissions::ROLE_PERMISSIONS), so it cannot reach
 * this page at all -- the same restriction that role already has against
 * the JSON endpoint. Not this fix's gap to close.
 *
 * None of the three write actions catch AuthorizationException the way
 * AuditCaseViewController's own writes do for their SoD-override case --
 * there, that exception means "you hold manage and legitimate access to
 * this exact case, just not this specific action without an override
 * reason," which reads well as an inline form error. Here, every
 * AuthorizationException CommunicationService throws means either "this
 * role has no business-rule standing to act at all" (sendNotice/close's
 * own national-role check, already unreachable through this controller
 * since the HTTP gate requires communications:manage first) or "this
 * thread is outside your tenant" (respond's cross-taxpayer check) -- both
 * genuine access denials, not business-rule feedback, so they're left
 * uncaught to fall through to the framework's own 403, the same
 * JSON-sibling behaviour and the same RT-002 clean-403 convention show()
 * already relies on for conversation()'s identical check.
 *
 * storeNotice's related_resource_type select is restricted to AUDIT_CASE
 * and RECONCILIATION_EXCEPTION only, even though REFUND_CLAIM is a valid
 * ComplianceValidator::CASE_REFERENCE_TYPES member -- CommunicationService::
 * resolveCaseReference() always rejects REFUND_CLAIM with a 422
 * ComplianceResourceException ("not yet supported by this migration -- the
 * refund_claims table has not been ported"), so offering it here would be
 * an RT-004-shaped button that can never succeed. The referenced case/
 * exception id is typed directly rather than selected from a generated
 * list, the same scope precedent AccessGovernanceViewController's own
 * certification form set (review_id typed directly).
 */
class CommunicationViewController extends Controller
{
    public function __construct(private readonly CommunicationService $communications) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $result = $this->communications->inbox($actor, $request->only(['status', 'related_resource_type', 'taxpayer_id']));

        return view('communications.index', [
            'threads' => $result['threads'],
            'totalCount' => $result['total_count'],
            'status' => $request->query('status', ''),
            'canManage' => $actor->hasAppPermission('communications:manage'),
        ]);
    }

    public function show(Request $request, string $id): View
    {
        $this->authorize('permission', 'compliance:read');
        $actor = $request->user();

        $conversation = $this->communications->conversation($id, $actor);
        abort_if(! $conversation, Response::HTTP_NOT_FOUND);
        $thread = $conversation['thread'];
        $messages = $conversation['messages'];

        $actorIds = collect($messages)->pluck('actor_id')
            ->push($thread['opened_by'])->push($thread['closed_by'])
            ->filter()->unique();
        $actorNames = User::whereIn('id', $actorIds)->pluck('name', 'id');

        return view('communications.show', [
            'thread' => $thread,
            'messages' => $messages,
            'actorNames' => $actorNames,
            'canManage' => $actor->hasAppPermission('communications:manage'),
            'canRespond' => $actor->hasAppPermission('communications:manage') || $actor->hasAppPermission('communications:respond'),
        ]);
    }

    public function storeNotice(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'communications:manage');

        $payload = [
            'schema_version' => '1.0.0',
            'related_resource_type' => (string) $request->input('related_resource_type'),
            'related_resource_id' => (string) $request->input('related_resource_id'),
            'channel' => (string) $request->input('channel'),
            'subject' => (string) $request->input('subject'),
            'content_summary' => (string) $request->input('content_summary'),
            'classification' => (string) $request->input('classification'),
        ];

        try {
            $thread = $this->communications->sendNotice($payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('communications.show', $thread['id'])->with('status', 'Notice sent.');
    }

    public function storeResponse(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'compliance:read');

        $payload = [
            'schema_version' => '1.0.0',
            'channel' => (string) $request->input('channel'),
            'content_summary' => (string) $request->input('content_summary'),
        ];

        try {
            $this->communications->respond($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('communications.show', $id)->with('status', 'Response sent.');
    }

    public function storeClosure(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'communications:manage');

        $payload = ['schema_version' => '1.0.0', 'reason' => (string) $request->input('reason')];

        try {
            $this->communications->close($id, $payload, $request->user(), $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (ComplianceValidationException $e) {
            return back()->withErrors($this->fieldErrors($e))->withInput();
        } catch (ComplianceResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('communications.show', $id)->with('status', 'Thread closed.');
    }

    /** @return array<string, string> */
    private function fieldErrors(ComplianceValidationException $e): array
    {
        return collect($e->errors())->mapWithKeys(fn (array $error) => [ltrim($error['path'], '/') ?: 'form' => $error['message']])->all();
    }
}
