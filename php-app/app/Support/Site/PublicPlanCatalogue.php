<?php

namespace App\Support\Site;

use App\Models\LicensePlan;
use App\Models\LicensePlanEntitlement;
use Illuminate\Database\QueryException;

/**
 * The commercial licence plans currently open for signup, with every
 * enabled feature and its limit, for the public /plans page and the
 * access section on the home page.
 *
 * Uses the same plan_domain / status / effective-window filter as
 * App\Services\Signup\SignupService::listPublicPlans() and submit(), so a
 * plan shown here is always one the signup form will accept. It also
 * carries what listPublicPlans() does not: each entitlement's limit_value
 * and a public-facing description of the feature.
 *
 * The marketing site must keep working if the database hiccups, so a
 * QueryException degrades to "no plans listed" (and is still reported)
 * instead of taking the home page down with it.
 */
class PublicPlanCatalogue
{
    /**
     * Public descriptions, keyed by feature_key. The seeded database
     * descriptions are written for administrators (the API_ACCESS one
     * mentions webhooks, whose delivery worker is not live yet), so the
     * public site says only what is true today. An unknown feature_key
     * falls back to its database description.
     */
    private const COPY = [
        'CORE_VAT' => 'Invoice certification, VAT returns and reconciliation workspaces.',
        'ADMINISTRATION' => 'Staff, roles, access governance and your organisation\'s security posture.',
        'USER_SEATS' => 'People in your organisation who can sign in.',
        'BRANCHES' => 'Operating branches managed under one organisation.',
        'ADVANCED_WORKFLOW' => 'Versioned, conditional approval workflows.',
        'ACCOUNTING' => 'General ledger and financial controls.',
        'INVENTORY' => 'Inventory and warehouse controls.',
        'PROJECTS' => 'Project costing, budgets and reports.',
        'ANALYTICS' => 'Governed reports and analytics.',
        'API_ACCESS' => 'Scoped API clients to connect your ERP or POS.',
    ];

    /** Display order: what the plan is for first, then supporting modules, then pure quotas. */
    private const ORDER = [
        'CORE_VAT', 'ADMINISTRATION', 'ADVANCED_WORKFLOW', 'ACCOUNTING', 'INVENTORY',
        'PROJECTS', 'ANALYTICS', 'API_ACCESS', 'USER_SEATS', 'BRANCHES',
    ];

    /** What one unit of each licence metric is called on the public site. */
    private const UNITS = [
        'USER_SEATS' => 'active users',
        'BRANCHES' => 'operating branches',
        'WORKFLOWS' => 'workflows',
        'REPORT_RUNS' => 'report runs',
        'API_REQUESTS' => 'API requests',
    ];

    /**
     * `quota` marks a feature that exists only to carry a limit (user seats,
     * branches: the feature key is its own metric), so the view can show it
     * as an allowance tile instead of repeating it in the capability list.
     *
     * @return list<array{
     *     code: string,
     *     name: string,
     *     features: list<array{key: string, name: string, description: ?string, limit: ?int, unit: ?string, quota: bool}>
     * }>
     */
    public static function open(): array
    {
        try {
            $now = now();
            $plans = LicensePlan::where('plan_domain', 'COMMERCIAL_SAAS')->where('status', 'ACTIVE')
                ->where('effective_from', '<=', $now)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))
                ->orderBy('name')->orderByDesc('version')->get()
                ->unique('code')->values();
            if ($plans->isEmpty()) {
                return [];
            }

            $entitlements = LicensePlanEntitlement::whereIn('license_plan_id', $plans->pluck('id'))
                ->where('enabled', true)->with('feature')->get()
                ->groupBy('license_plan_id');
        } catch (QueryException $e) {
            report($e);

            return [];
        }

        return $plans->map(fn (LicensePlan $plan) => [
            'code' => $plan->code,
            'name' => $plan->name,
            'features' => $entitlements->get($plan->id, collect())
                ->filter(fn (LicensePlanEntitlement $entitlement) => $entitlement->feature !== null)
                ->map(function (LicensePlanEntitlement $entitlement) {
                    $feature = $entitlement->feature;
                    $limit = $entitlement->limit_value === null ? null : (int) $entitlement->limit_value;
                    $unit = self::UNITS[$feature->metric_key] ?? null;

                    return [
                        'key' => $feature->feature_key,
                        'name' => $feature->name,
                        'description' => self::COPY[$feature->feature_key] ?? ($feature->description ?: null),
                        'limit' => $limit,
                        'unit' => $unit,
                        'quota' => $feature->feature_key === $feature->metric_key,
                    ];
                })
                ->sortBy(fn (array $feature) => ($position = array_search($feature['key'], self::ORDER, true)) === false ? count(self::ORDER) : $position)
                ->values()->all(),
        ])->all();
    }
}
