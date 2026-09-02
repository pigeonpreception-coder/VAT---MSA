<?php

namespace App\Services\Navigation;

use App\Domain\Navigation\NavigationValidator;
use App\Exceptions\LicensingValidationException;
use App\Models\NavigationFolder;
use App\Models\NavigationItem;
use App\Models\NavigationPreference;
use App\Models\NavigationWorkspace;
use App\Models\User;
use App\Support\Licensing\LicenseResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/control-plane-repository.ts's getEffectiveNavigation/
 * getNavigationChildren/getNavigationItemActions/saveNavigationPreference/
 * searchWorkspace -- Phase 12's Workspace & Navigation domain.
 * `getNavigationAccessContext`/`navigationRowAllowed` (the source's own
 * two shared internal helpers) are `accessContext()`/`rowAllowed()`
 * below. `searchWorkspace` closes out the very last function left in
 * `control-plane-repository.ts` besides `getAdministrationSnapshot`
 * itself (see docs/MIGRATION_MATRIX.md).
 */
class NavigationService
{
    /**
     * The source's own `actor.capabilities` guard
     * (`actor.organisationId === organisation.id ? actor.capabilities : []`)
     * exists to avoid leaking a user's *own* organisation's capability
     * grants into a *different* requested organisation's context. This
     * port has no session-cached `actor.organisationId`/`actor.capabilities`
     * to compare against (the same simplification `LicenseResolver::
     * resolveOrganisation`'s own doc comment already established), so it
     * queries `user_capability_assignments` directly, scoped to the
     * *resolved* organisation -- for a taxpayer-scoped actor (the
     * overwhelming common case) the resolved organisation always *is*
     * their own, so this is exactly equivalent; for a national actor
     * requesting an arbitrary organisation, it returns the same empty set
     * the source would (no such assignment row can exist without a real
     * membership, which `grantCapability` itself requires).
     *
     * @return array{enabledFeatures: list<string>, capabilitySet: list<string>}
     */
    private function accessContext(User $actor, string $organisationId, array $license): array
    {
        $entitlements = LicenseResolver::getEntitlements($license);
        $enabledFeatures = array_values(array_map(
            fn ($e) => $e['feature_key'],
            array_filter($entitlements, fn ($e) => $e['enabled']),
        ));

        $orgCapabilities = DB::table('organisation_capabilities')
            ->where('organisation_id', $organisationId)->where('status', 'ACTIVE')
            ->pluck('capability')->all();
        $ownCapabilities = DB::table('user_capability_assignments')
            ->where('organisation_id', $organisationId)->where('user_id', $actor->id)->where('status', 'ACTIVE')
            ->pluck('capability')->all();

        return [
            'enabledFeatures' => $enabledFeatures,
            'capabilitySet' => array_values(array_unique([...$orgCapabilities, ...$ownCapabilities])),
        ];
    }

    private function rowAllowed(User $actor, ?string $featureKey, ?string $capability, string $requiredPermission, array $context): bool
    {
        if (! $actor->hasAppPermission($requiredPermission)) {
            return false;
        }
        if ($featureKey && ! in_array($featureKey, $context['enabledFeatures'], true)) {
            return false;
        }
        if ($capability && ! in_array($capability, $context['capabilitySet'], true)) {
            return false;
        }

        return true;
    }

    /** @return array{organisation: array{id: string}, workspaces: list<array<string, mixed>>} */
    public function getEffectiveNavigation(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
        $context = $this->accessContext($actor, $organisation->id, $license);

        $rows = DB::table('navigation_workspaces as w')
            ->join('navigation_folders as f', fn ($j) => $j->on('f.workspace_id', '=', 'w.id')->where('f.status', 'ACTIVE'))
            ->join('navigation_items as i', fn ($j) => $j->on('i.folder_id', '=', 'f.id')->where('i.status', 'ACTIVE'))
            ->where('w.status', 'ACTIVE')
            ->orderBy('w.sort_order')->orderBy('f.sort_order')->orderBy('i.sort_order')
            ->select([
                'w.id as workspace_id', 'w.workspace_key', 'w.label as workspace_label', 'w.description',
                'w.classification as workspace_classification', 'f.id as folder_id', 'f.folder_key', 'f.label as folder_label',
                'i.id as item_id', 'i.item_key', 'i.label as item_label', 'i.href', 'i.feature_key', 'i.capability',
                'i.required_permission', 'i.classification as item_classification',
            ])->get();

        $byWorkspace = [];
        $folderIndex = [];
        foreach ($rows as $row) {
            if (! $this->rowAllowed($actor, $row->feature_key, $row->capability, $row->required_permission, $context)) {
                continue;
            }
            if (! isset($byWorkspace[$row->workspace_id])) {
                $byWorkspace[$row->workspace_id] = [
                    'id' => $row->workspace_id, 'key' => $row->workspace_key, 'label' => $row->workspace_label,
                    'description' => $row->description, 'classification' => $row->workspace_classification, 'folders' => [],
                ];
            }
            if (! isset($folderIndex[$row->folder_id])) {
                $folderIndex[$row->folder_id] = ['id' => $row->folder_id, 'key' => $row->folder_key, 'label' => $row->folder_label, 'items' => []];
                $byWorkspace[$row->workspace_id]['folders'][] = &$folderIndex[$row->folder_id];
            }
            $folderIndex[$row->folder_id]['items'][] = [
                'id' => $row->item_id, 'key' => $row->item_key, 'label' => $row->item_label,
                'href' => $row->href, 'classification' => $row->item_classification,
            ];
        }
        unset($folderIndex);

        return ['organisation' => ['id' => $organisation->id], 'workspaces' => array_values($byWorkspace)];
    }

    /**
     * A scoped drill-down instead of fetching the whole tree via
     * getEffectiveNavigation -- a workspace's top-level folders, or one
     * folder's sub-folders and items, properly respecting
     * navigation_folders.parent_folder_id (which getEffectiveNavigation's
     * flat query does not traverse). Folders themselves are never
     * permission-gated in the source (only items are) -- reproduced
     * faithfully, not "fixed".
     *
     * @return array<string, mixed>
     */
    public function getNavigationChildren(User $actor, mixed $parentType, mixed $parentId, ?string $requestedOrganisationId): array
    {
        $query = NavigationValidator::childrenQuery($parentType, $parentId);
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
        $context = $this->accessContext($actor, $organisation->id, $license);

        if ($query['parentType'] === 'workspace') {
            $workspace = NavigationWorkspace::where('id', $query['parentId'])->where('status', 'ACTIVE')->first();
            if (! $workspace) {
                throw new LicensingValidationException('WORKSPACE_NOT_FOUND', 'The navigation workspace does not exist.');
            }
            $folders = NavigationFolder::where('workspace_id', $workspace->id)->whereNull('parent_folder_id')
                ->where('status', 'ACTIVE')->orderBy('sort_order')->get();

            return [
                'parentType' => 'workspace',
                'workspace' => ['id' => $workspace->id, 'key' => $workspace->workspace_key, 'label' => $workspace->label, 'description' => $workspace->description, 'classification' => $workspace->classification],
                'folders' => $folders->map(fn ($f) => ['id' => $f->id, 'key' => $f->folder_key, 'label' => $f->label])->values()->all(),
            ];
        }

        $folder = NavigationFolder::where('id', $query['parentId'])->where('status', 'ACTIVE')->first();
        if (! $folder) {
            throw new LicensingValidationException('FOLDER_NOT_FOUND', 'The navigation folder does not exist.');
        }
        $subfolders = NavigationFolder::where('parent_folder_id', $folder->id)->where('status', 'ACTIVE')->orderBy('sort_order')->get();
        $items = NavigationItem::where('folder_id', $folder->id)->where('status', 'ACTIVE')->orderBy('sort_order')->get();

        return [
            'parentType' => 'folder',
            'folder' => ['id' => $folder->id, 'key' => $folder->folder_key, 'label' => $folder->label],
            'folders' => $subfolders->map(fn ($f) => ['id' => $f->id, 'key' => $f->folder_key, 'label' => $f->label])->values()->all(),
            'items' => $items->filter(fn ($item) => $this->rowAllowed($actor, $item->feature_key, $item->capability, $item->required_permission, $context))
                ->map(fn ($item) => ['id' => $item->id, 'key' => $item->item_key, 'label' => $item->label, 'href' => $item->href, 'classification' => $item->classification])
                ->values()->all(),
        ];
    }

    /**
     * Whether the actor can act on one specific navigation item right now,
     * and why not if not -- for a route guard or a disabled-nav-item
     * tooltip, without walking the whole tree.
     *
     * @return array<string, mixed>
     */
    public function getNavigationItemActions(User $actor, mixed $itemKey, ?string $requestedOrganisationId): array
    {
        $key = trim((string) ($itemKey ?? ''));
        if ($key === '') {
            throw new LicensingValidationException('ITEM_KEY_REQUIRED', 'item_key is required.');
        }
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
        $context = $this->accessContext($actor, $organisation->id, $license);

        $item = NavigationItem::where('item_key', $key)->where('status', 'ACTIVE')->first();
        if (! $item) {
            throw new LicensingValidationException('NAVIGATION_ITEM_NOT_FOUND', 'The navigation item does not exist.');
        }

        $deniedReasons = [];
        if (! $actor->hasAppPermission($item->required_permission)) {
            $deniedReasons[] = "Requires permission {$item->required_permission}.";
        }
        if ($item->feature_key && ! in_array($item->feature_key, $context['enabledFeatures'], true)) {
            $deniedReasons[] = "Requires licensed feature {$item->feature_key}.";
        }
        if ($item->capability && ! in_array($item->capability, $context['capabilitySet'], true)) {
            $deniedReasons[] = "Requires {$item->capability} capability.";
        }
        $allowed = count($deniedReasons) === 0;

        return [
            'id' => $item->id, 'key' => $item->item_key, 'label' => $item->label, 'href' => $item->href,
            'classification' => $item->classification, 'allowed' => $allowed,
            'actions' => $allowed ? [['action' => 'VIEW', 'href' => $item->href]] : [],
            'deniedReasons' => $deniedReasons,
        ];
    }

    /**
     * A low-risk, self-scoped write (always the caller's own preference) --
     * deliberately skips the audit_events/outbox_events machinery every
     * other mutating command elsewhere in this migration uses, since a UI
     * preference like a collapsed sidebar isn't a privileged or statutory
     * action. Upserts via the (user_id, organisation_id, preference_type)
     * unique index -- matching the source's own `ON CONFLICT ... DO
     * UPDATE`.
     *
     * @return array<string, mixed>
     */
    public function saveNavigationPreference(User $actor, array $payload, ?string $requestedOrganisationId): array
    {
        $preference = NavigationValidator::preference($payload);
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $now = now();

        NavigationPreference::updateOrCreate(
            ['user_id' => $actor->id, 'organisation_id' => $organisation->id, 'preference_type' => $preference['preferenceType']],
            ['value' => $preference['value'], 'updated_at' => $now],
        );

        return [
            'userId' => $actor->id, 'organisationId' => $organisation->id,
            'preferenceType' => $preference['preferenceType'], 'value' => json_decode($preference['value'], true),
        ];
    }

    /**
     * A permission-filtered search across employees, invoices, and
     * organisation roles -- each section only runs (and only ever
     * surfaces matches from) a table the actor already holds real read
     * access to, matching the source's own per-section `hasPermission`
     * guards exactly. `%`/`_` are stripped from the term before building
     * the `LIKE` pattern so a search string can't inject its own SQL
     * wildcards. The `search:read` check here is intentionally redundant
     * with the route's own `Gate::authorize('permission', 'search:read')`
     * -- reproduced faithfully because the source itself double-checks it
     * inside the function, not because this port invented the redundancy.
     *
     * @return list<array{type: string, id: string, title: string, subtitle: string, href: string}>
     */
    public function searchWorkspace(User $actor, string $query, ?string $requestedOrganisationId): array
    {
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        if (! $actor->hasAppPermission('search:read')) {
            throw new AuthorizationException('Workspace search is not authorised.');
        }
        $term = mb_substr(trim($query), 0, 80);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.str_replace(['%', '_'], '', $term).'%';

        $results = [];
        if ($actor->hasAppPermission('employees:read')) {
            $rows = DB::table('employees')->where('organisation_id', $organisation->id)
                ->where(fn ($q) => $q->where('full_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('employee_number', 'like', $like))
                ->limit(15)->get(['id', 'full_name', 'email', 'employee_number']);
            foreach ($rows as $row) {
                $results[] = ['type' => 'Employee', 'id' => $row->id, 'title' => $row->full_name, 'subtitle' => "{$row->employee_number} \u{00B7} {$row->email}", 'href' => '/administration#employees'];
            }
        }
        if ($actor->hasAppPermission('invoices:read')) {
            $rows = DB::table('invoices')
                ->where(fn ($q) => $q->where('supplier_taxpayer_id', $organisation->taxpayer_id)->orWhere('customer_taxpayer_id', $organisation->taxpayer_id))
                ->where(fn ($q) => $q->where('invoice_number', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('customer_name', 'like', $like))
                ->limit(15)->get(['id', 'invoice_number', 'supplier_name', 'customer_name']);
            foreach ($rows as $row) {
                $results[] = ['type' => 'Invoice', 'id' => $row->id, 'title' => $row->invoice_number, 'subtitle' => "{$row->supplier_name} \u{2192} {$row->customer_name}", 'href' => "/invoices/{$row->id}"];
            }
        }
        if ($actor->hasAppPermission('roles:read')) {
            $rows = DB::table('organisation_roles')->where('organisation_id', $organisation->id)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like))
                ->limit(15)->get(['id', 'name', 'description']);
            foreach ($rows as $row) {
                $results[] = ['type' => 'Role', 'id' => $row->id, 'title' => $row->name, 'subtitle' => $row->description, 'href' => '/administration#roles'];
            }
        }

        return array_slice($results, 0, 30);
    }
}
