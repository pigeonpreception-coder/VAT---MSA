import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope, requirePermission } from "@/lib/auth";
import { evaluateEntitlement, quarterlyAccessReviewWindow, type CapacityMode, type EntitlementEvaluation, type LicenseState, type OperationClass } from "@/lib/domain/control-plane";
import type { UserContext } from "@/lib/domain/types";

export type AuthorityDomain = "GOVERNMENT_TAX" | "COMMERCIAL_SAAS" | "PLATFORM_CONTROL";
export type LicensedOrganisationScope = { id: string; taxpayer_id: string; legal_name: string };
export type OrganisationLicense = {
  id: string;
  organisation_id: string;
  plan_id: string;
  plan_code: string;
  plan_name: string;
  plan_version: number;
  plan_domain: "COMMERCIAL_SAAS";
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
  capacity_mode: CapacityMode;
  limit_value: number | null;
  used_value: number | null;
  reserved_value: number | null;
};
export type LicensePermissionPolicy = { permission_code: string; feature_key: string; operation_class: OperationClass; authority_domain: AuthorityDomain };
export type TaxAuthorizationEvidence = {
  tax_subscription_id: string;
  tax_authority_id: string;
  jurisdiction_id: string;
  taxpayer_authorization_id: string | null;
  evidence_kind: "AUTHORITY_USER" | "TAXPAYER_AUTHORIZATION";
};
export type LicenseDecision = {
  authorityDomain: AuthorityDomain;
  organisation: LicensedOrganisationScope;
  license: OrganisationLicense | null;
  taxAuthorization: TaxAuthorizationEvidence | null;
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
  const row = await db.prepare("SELECT id,taxpayer_id,legal_name FROM organisations WHERE status='ACTIVE' ORDER BY legal_name LIMIT 1").first<LicensedOrganisationScope>();
  if (!row) throw new AccessDeniedError("No active organisation is available in this environment.");
  return row;
}

export async function getOrganisationLicense(db: D1Database, organisationId: string): Promise<OrganisationLicense> {
  const row = await db.prepare(`SELECT l.id,l.organisation_id,p.id AS plan_id,p.code AS plan_code,p.name AS plan_name,p.version AS plan_version,
    p.plan_domain,l.state,l.retention_policy,s.current_period_start,s.current_period_end
    FROM organisation_licenses l JOIN license_plans p ON p.id=l.license_plan_id JOIN subscriptions s ON s.id=l.subscription_id
    WHERE l.organisation_id=? AND p.plan_domain='COMMERCIAL_SAAS' AND s.subscription_domain='COMMERCIAL_SAAS'
    ORDER BY l.effective_from DESC LIMIT 1`).bind(organisationId).first<OrganisationLicense>();
  if (!row) throw new AccessDeniedError("The organisation has no configured licence.");
  return row;
}

export async function getLicenseEntitlements(db: D1Database, license: OrganisationLicense): Promise<LicenseEntitlement[]> {
  const result = await db.prepare(`SELECT e.feature_key,f.name,f.description,f.metric_key,e.enabled,e.capacity_mode,e.limit_value,
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

async function assertGovernmentTaxFeatureOperation(
  actor: UserContext,
  featureKey: string,
  options: { requestedOrganisationId?: string | null } = {},
): Promise<LicenseDecision> {
  const db = await ensureDatabase();
  const organisation = await resolveLicensedOrganisation(actor, options.requestedOrganisationId);
  let evidence: TaxAuthorizationEvidence | null = null;
  if (isNationalScope(actor)) {
    evidence = await db.prepare(`SELECT ts.id AS tax_subscription_id,ta.id AS tax_authority_id,tj.id AS jurisdiction_id,
      NULL AS taxpayer_authorization_id,'AUTHORITY_USER' AS evidence_kind
      FROM tax_authority_users tau
      JOIN tax_authorities ta ON ta.id=tau.tax_authority_id AND ta.status='ACTIVE'
      JOIN tax_jurisdictions tj ON tj.id=ta.jurisdiction_id AND tj.status='ACTIVE'
      JOIN tax_subscriptions ts ON ts.tax_authority_id=ta.id AND ts.status='ACTIVE'
        AND datetime(ts.effective_from)<=CURRENT_TIMESTAMP
        AND (ts.effective_to IS NULL OR datetime(ts.effective_to)>CURRENT_TIMESTAMP)
      JOIN tax_subscription_features tf ON tf.tax_subscription_id=ts.id AND tf.feature_key=? AND tf.status='ACTIVE'
      JOIN license_features f ON f.feature_key=tf.feature_key AND f.authority_domain='GOVERNMENT_TAX'
      WHERE tau.user_id=? AND tau.status='ACTIVE' AND datetime(tau.effective_from)<=CURRENT_TIMESTAMP
        AND (tau.effective_to IS NULL OR datetime(tau.effective_to)>CURRENT_TIMESTAMP)
      LIMIT 1`).bind(featureKey, actor.userId).first<TaxAuthorizationEvidence>();
  } else {
    evidence = await db.prepare(`SELECT ts.id AS tax_subscription_id,ta.id AS tax_authority_id,tj.id AS jurisdiction_id,
      tpa.id AS taxpayer_authorization_id,'TAXPAYER_AUTHORIZATION' AS evidence_kind
      FROM taxpayer_authorizations tpa
      JOIN tax_authorities ta ON ta.id=tpa.tax_authority_id AND ta.status='ACTIVE'
      JOIN tax_jurisdictions tj ON tj.id=tpa.jurisdiction_id AND tj.status='ACTIVE'
      JOIN tax_subscriptions ts ON ts.id=tpa.tax_subscription_id AND ts.tax_authority_id=ta.id AND ts.status='ACTIVE'
        AND datetime(ts.effective_from)<=CURRENT_TIMESTAMP
        AND (ts.effective_to IS NULL OR datetime(ts.effective_to)>CURRENT_TIMESTAMP)
      JOIN tax_subscription_features tf ON tf.tax_subscription_id=ts.id AND tf.feature_key=? AND tf.status='ACTIVE'
      JOIN license_features f ON f.feature_key=tf.feature_key AND f.authority_domain='GOVERNMENT_TAX'
      WHERE tpa.organisation_id=? AND tpa.taxpayer_id=? AND tpa.status='ACTIVE'
        AND tpa.vat_registration_status='ACTIVE' AND datetime(tpa.effective_from)<=CURRENT_TIMESTAMP
        AND (tpa.effective_to IS NULL OR datetime(tpa.effective_to)>CURRENT_TIMESTAMP)
      LIMIT 1`).bind(featureKey, organisation.id, actor.taxpayerId).first<TaxAuthorizationEvidence>();
  }
  if (!evidence) {
    throw new AccessDeniedError("GOVERNMENT_TAX_AUTHORIZATION_REQUIRED: An active authority subscription and scoped tax authorization are required.");
  }
  return {
    authorityDomain: "GOVERNMENT_TAX",
    organisation,
    license: null,
    taxAuthorization: evidence,
    policy: null,
    evaluation: {
      allowed: true,
      code: "GOVERNMENT_TAX_AUTHORIZED",
      reason: "The independent Government Tax Authorization Service permits this tax operation.",
      remaining: null,
      obligations: ["TAX_SCOPE_AUDIT", "IGNORE_COMMERCIAL_LICENSE_AS_GRANT"],
    },
  };
}

async function assertPlatformControlOperation(actor: UserContext): Promise<LicenseDecision> {
  const organisation = await resolveLicensedOrganisation(actor);
  return {
    authorityDomain: "PLATFORM_CONTROL",
    organisation,
    license: null,
    taxAuthorization: null,
    policy: null,
    evaluation: {
      allowed: true,
      code: "PLATFORM_ROLE_AUTHORIZED",
      reason: "The platform-control role and permission permit this operation without a domain subscription grant.",
      remaining: null,
      obligations: ["PRIVILEGED_AUDIT", "NO_IMPLICIT_DOMAIN_MEMBERSHIP"],
    },
  };
}

export async function assertLicensedFeatureOperation(
  actor: UserContext,
  featureKey: string,
  operationClass: OperationClass,
  options: { requested?: number; requestedOrganisationId?: string | null; enforceAccessReview?: boolean } = {},
): Promise<LicenseDecision> {
  const db = await ensureDatabase();
  const organisation = await resolveLicensedOrganisation(actor, options.requestedOrganisationId);
  const feature = await db.prepare("SELECT authority_domain FROM license_features WHERE feature_key=?")
    .bind(featureKey).first<{ authority_domain: AuthorityDomain }>();
  if (!feature || feature.authority_domain !== "COMMERCIAL_SAAS") {
    throw new AccessDeniedError(`AUTHORITY_DOMAIN_MISMATCH: ${featureKey} is not a commercial SaaS entitlement.`);
  }
  const license = await getOrganisationLicense(db, organisation.id);
  const entitlement = (await getLicenseEntitlements(db, license)).find((item) => item.feature_key === featureKey);
  const evaluation = evaluateEntitlement({
    licenseState: license.state,
    featureKey,
    featureEnabled: entitlement?.enabled === 1,
    operationClass,
    capacityMode: entitlement?.capacity_mode ?? "NOT_APPLICABLE",
    limit: entitlement?.limit_value ?? null,
    used: entitlement?.used_value ?? 0,
    reserved: entitlement?.reserved_value ?? 0,
    requested: options.requested ?? 0,
  });
  if (!evaluation.allowed) throw new AccessDeniedError(`${evaluation.code}: ${evaluation.reason}`);
  if (operationClass === "ADMIN_WRITE" && options.enforceAccessReview !== false) await requireCurrentAccessReview(db, organisation.id);
  return { authorityDomain: "COMMERCIAL_SAAS", organisation, license, taxAuthorization: null, policy: null, evaluation };
}

export async function requireLicensedPermission(
  actor: UserContext,
  permission: string,
  options: { operationClass?: OperationClass; requested?: number; requestedOrganisationId?: string | null; enforceAccessReview?: boolean } = {},
): Promise<LicenseDecision> {
  requirePermission(actor, permission);
  const db = await ensureDatabase();
  const policy = await db.prepare(`SELECT p.permission_code,p.feature_key,p.operation_class,f.authority_domain
    FROM license_permission_policies p JOIN license_features f ON f.feature_key=p.feature_key
    WHERE p.permission_code=? AND p.status='ACTIVE' LIMIT 1`)
    .bind(permission).first<LicensePermissionPolicy>();
  if (!policy) throw new AccessDeniedError(`LICENSE_POLICY_MISSING: ${permission} has no approved licence policy and is denied by default.`);
  const decision = policy.authority_domain === "GOVERNMENT_TAX"
    ? await assertGovernmentTaxFeatureOperation(actor, policy.feature_key, options)
    : policy.authority_domain === "PLATFORM_CONTROL"
      ? await assertPlatformControlOperation(actor)
      : await assertLicensedFeatureOperation(actor, policy.feature_key, options.operationClass ?? policy.operation_class, options);
  return { ...decision, policy };
}
