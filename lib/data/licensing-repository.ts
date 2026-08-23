import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope, requirePermission } from "@/lib/auth";
import { evaluateEntitlement, quarterlyAccessReviewWindow, type EntitlementEvaluation, type LicenseState, type OperationClass } from "@/lib/domain/control-plane";
import type { UserContext } from "@/lib/domain/types";

export type LicensedOrganisationScope = { id: string; taxpayer_id: string; legal_name: string };
export type OrganisationLicense = {
  id: string;
  organisation_id: string;
  plan_id: string;
  plan_code: string;
  plan_name: string;
  plan_version: number;
  state: LicenseState;
  retention_policy: string;
  current_period_start: string;
  current_period_end: string;
};
export type LicenseEntitlement = {
  feature_key: string;
  name: string;
  description: string;
  metric_key: string | null;
  enabled: number;
  limit_value: number | null;
  used_value: number | null;
  reserved_value: number | null;
};
export type LicensePermissionPolicy = { permission_code: string; feature_key: string; operation_class: OperationClass };
export type LicenseDecision = {
  organisation: LicensedOrganisationScope;
  license: OrganisationLicense;
  policy: LicensePermissionPolicy | null;
  evaluation: EntitlementEvaluation;
};

export async function resolveLicensedOrganisation(actor: UserContext, requestedOrganisationId?: string | null): Promise<LicensedOrganisationScope> {
  const db = await ensureDatabase();
  const organisationId = requestedOrganisationId ?? actor.organisationId;
  if (organisationId) {
    const row = await db.prepare("SELECT id,taxpayer_id,legal_name FROM organisations WHERE id=? AND status='ACTIVE'")
      .bind(organisationId).first<LicensedOrganisationScope>();
    if (!row) throw new AccessDeniedError("The organisation scope is unavailable.");
    if (!isNationalScope(actor) && row.taxpayer_id !== actor.taxpayerId) throw new AccessDeniedError("The requested organisation is outside your authorised scope.");
    return row;
  }
  if (!isNationalScope(actor)) throw new AccessDeniedError("An active organisation membership is required.");
  const row = await db.prepare(`SELECT o.id,o.taxpayer_id,o.legal_name FROM organisations o
    JOIN organisation_licenses l ON l.organisation_id=o.id
    WHERE o.status='ACTIVE' ORDER BY o.legal_name LIMIT 1`).first<LicensedOrganisationScope>();
  if (!row) throw new AccessDeniedError("No licensed organisation is available in this environment.");
  return row;
}

export async function getOrganisationLicense(db: D1Database, organisationId: string): Promise<OrganisationLicense> {
  const row = await db.prepare(`SELECT l.id,l.organisation_id,p.id AS plan_id,p.code AS plan_code,p.name AS plan_name,p.version AS plan_version,
    l.state,l.retention_policy,s.current_period_start,s.current_period_end
    FROM organisation_licenses l JOIN license_plans p ON p.id=l.license_plan_id JOIN subscriptions s ON s.id=l.subscription_id
    WHERE l.organisation_id=? ORDER BY l.effective_from DESC LIMIT 1`).bind(organisationId).first<OrganisationLicense>();
  if (!row) throw new AccessDeniedError("The organisation has no configured licence.");
  return row;
}

export async function getLicenseEntitlements(db: D1Database, license: OrganisationLicense): Promise<LicenseEntitlement[]> {
  const result = await db.prepare(`SELECT e.feature_key,f.name,f.description,f.metric_key,e.enabled,e.limit_value,
    COALESCE((SELECT u.used_value FROM license_usage u WHERE u.organisation_license_id=? AND u.metric_key=f.metric_key ORDER BY u.updated_at DESC LIMIT 1),0) AS used_value,
    COALESCE((SELECT u.reserved_value FROM license_usage u WHERE u.organisation_license_id=? AND u.metric_key=f.metric_key ORDER BY u.updated_at DESC LIMIT 1),0) AS reserved_value
    FROM license_plan_entitlements e JOIN license_features f ON f.feature_key=e.feature_key
    WHERE e.license_plan_id=? ORDER BY f.name`).bind(license.id, license.id, license.plan_id).all<LicenseEntitlement>();
  return result.results;
}

async function requireCurrentAccessReview(db: D1Database, organisationId: string): Promise<void> {
  const reviewWindow = quarterlyAccessReviewWindow();
  const review = await db.prepare(`SELECT id,status,due_at FROM access_reviews
    WHERE organisation_id=? AND review_type='QUARTERLY' AND period_start=? AND status IN ('OPEN','COMPLETED') LIMIT 1`)
    .bind(organisationId, reviewWindow.periodStart).first<{ id: string; status: string; due_at: string }>();
  if (!review || (review.status === "OPEN" && Date.parse(review.due_at) < Date.now())) {
    throw new AccessDeniedError(`QUARTERLY_ACCESS_REVIEW_REQUIRED: Open or complete the ${reviewWindow.key} access review before privileged organisation changes.`);
  }
}

export async function assertLicensedFeatureOperation(
  actor: UserContext,
  featureKey: string,
  operationClass: OperationClass,
  options: { requested?: number; requestedOrganisationId?: string | null; enforceAccessReview?: boolean } = {},
): Promise<LicenseDecision> {
  const db = await ensureDatabase();
  const organisation = await resolveLicensedOrganisation(actor, options.requestedOrganisationId);
  const license = await getOrganisationLicense(db, organisation.id);
  const entitlement = (await getLicenseEntitlements(db, license)).find((item) => item.feature_key === featureKey);
  const evaluation = evaluateEntitlement({
    licenseState: license.state,
    featureKey,
    featureEnabled: entitlement?.enabled === 1,
    operationClass,
    limit: entitlement?.limit_value ?? null,
    used: entitlement?.used_value ?? 0,
    reserved: entitlement?.reserved_value ?? 0,
    requested: options.requested ?? 0,
  });
  if (!evaluation.allowed) throw new AccessDeniedError(`${evaluation.code}: ${evaluation.reason}`);
  if (operationClass === "ADMIN_WRITE" && options.enforceAccessReview !== false) await requireCurrentAccessReview(db, organisation.id);
  return { organisation, license, policy: null, evaluation };
}

export async function requireLicensedPermission(
  actor: UserContext,
  permission: string,
  options: { operationClass?: OperationClass; requested?: number; requestedOrganisationId?: string | null; enforceAccessReview?: boolean } = {},
): Promise<LicenseDecision> {
  requirePermission(actor, permission);
  const db = await ensureDatabase();
  const policy = await db.prepare(`SELECT permission_code,feature_key,operation_class
    FROM license_permission_policies WHERE permission_code=? AND status='ACTIVE' LIMIT 1`)
    .bind(permission).first<LicensePermissionPolicy>();
  if (!policy) throw new AccessDeniedError(`LICENSE_POLICY_MISSING: ${permission} has no approved licence policy and is denied by default.`);
  const decision = await assertLicensedFeatureOperation(actor, policy.feature_key, options.operationClass ?? policy.operation_class, options);
  return { ...decision, policy };
}
