import { ensureDatabase } from "@/db/runtime";
import { isNationalScope } from "@/lib/auth";
import {
  SELF_SERVE_PRIVACY_NOTICE_VERSION,
  SELF_SERVE_TERMS_VERSION,
  SignupValidationError,
  type NormalizedSelfServeSignup,
} from "@/lib/domain/signup";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

export type PublicSignupPlan = {
  code: string;
  name: string;
  version: number;
  features: string[];
};

export type SelfServeIdentityClaim = {
  provider: "SITES_WORKSPACE";
  subject: string;
  email: string;
};

export type SelfServeSignupAccepted = {
  application_reference: string;
  status: "PENDING_VERIFICATION";
  identity_status: "VERIFICATION_REQUIRED" | "EXTERNALLY_ASSERTED";
  taxpayer_verification_status: "AWAITING_PROVIDER_CONTRACT";
  licence_status: "NOT_ACTIVATED";
  submitted_at: string;
  next_action: string;
};

export type SelfServeSignupSummary = {
  id: string;
  public_reference: string;
  applicant_name: string;
  applicant_role: string;
  contact_email: string;
  legal_name: string;
  vat_number: string;
  tin: string;
  plan_code: string;
  plan_name: string;
  status: string;
  identity_status: string;
  taxpayer_verification_status: string;
  licence_status: string;
  submitted_at: string;
};

type SignupPlanRow = {
  id: string;
  code: string;
  name: string;
  version: number;
  feature_names: string | null;
};

type IdempotentSignupRow = SelfServeSignupAccepted & {
  request_hash: string;
};

const ACTIVE_SIGNUP_STATES = "('PENDING_VERIFICATION','UNDER_REVIEW','APPROVED_FOR_PROVISIONING')";

export async function listPublicSignupPlans(now = new Date().toISOString()): Promise<PublicSignupPlan[]> {
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT p.id,p.code,p.name,p.version,
    GROUP_CONCAT(CASE WHEN e.enabled=1 THEN f.name END, '|') AS feature_names
    FROM license_plans p
    LEFT JOIN license_plan_entitlements e ON e.license_plan_id=p.id
    LEFT JOIN license_features f ON f.feature_key=e.feature_key
    WHERE p.status='ACTIVE' AND p.plan_domain='COMMERCIAL_SAAS' AND datetime(p.effective_from) <= datetime(?)
      AND (p.effective_to IS NULL OR datetime(p.effective_to) > datetime(?))
    GROUP BY p.id,p.code,p.name,p.version
    ORDER BY p.name,p.version DESC`).bind(now, now).all<SignupPlanRow>();
  return result.results.map((plan) => ({
    code: plan.code,
    name: plan.name,
    version: Number(plan.version),
    features: plan.feature_names?.split("|").filter(Boolean) ?? [],
  }));
}

export async function listSelfServeSignupApplications(user: UserContext): Promise<SelfServeSignupSummary[]> {
  if (!isNationalScope(user)) return [];
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT s.id,s.public_reference,s.applicant_name,s.applicant_role,s.contact_email,
    s.legal_name,s.vat_number,s.tin,p.code AS plan_code,p.name AS plan_name,s.status,s.identity_status,
    s.taxpayer_verification_status,s.licence_status,s.submitted_at
    FROM self_serve_signup_applications s
    JOIN license_plans p ON p.id=s.requested_plan_id
    ORDER BY s.submitted_at DESC LIMIT 100`).all<SelfServeSignupSummary>();
  return result.results;
}

export async function submitSelfServeSignup(
  signup: NormalizedSelfServeSignup,
  idempotencyKey: string,
  correlationId: string,
  identity: SelfServeIdentityClaim | null,
): Promise<SelfServeSignupAccepted> {
  if (idempotencyKey.length < 16 || idempotencyKey.length > 128) {
    throw new SignupValidationError([{ code: "IDEMPOTENCY_KEY_INVALID", path: "/headers/idempotency-key", message: "Idempotency key must contain 16 to 128 characters." }]);
  }
  if (identity && identity.email.trim().toLowerCase() !== signup.contact_email) {
    throw new SignupValidationError([{ code: "IDENTITY_EMAIL_MISMATCH", path: "/contact_email", message: "The contact email must match the authenticated workspace identity." }]);
  }

  const db = await ensureDatabase();
  const now = new Date().toISOString();
  const identitySubjectHash = identity ? `sha256:${await sha256Hex(identity.subject)}` : null;
  const requestHash = await sha256Hex(stableStringify({
    signup,
    identity_provider: identity?.provider ?? null,
    identity_subject_hash: identitySubjectHash,
    terms_version: SELF_SERVE_TERMS_VERSION,
    privacy_notice_version: SELF_SERVE_PRIVACY_NOTICE_VERSION,
  }));

  const prior = await db.prepare(`SELECT public_reference AS application_reference,status,identity_status,
    taxpayer_verification_status,licence_status,submitted_at,request_hash,
    'The commercial application is held for administrator and organisation verification. No account, payment, subscription or licence has been activated.' AS next_action
    FROM self_serve_signup_applications WHERE contact_email=? AND idempotency_key=?`)
    .bind(signup.contact_email, idempotencyKey).first<IdempotentSignupRow>();
  if (prior) {
    if (prior.request_hash !== requestHash) {
      throw new RepositoryConflictError("The idempotency key was already used for a different signup application.");
    }
    return prior;
  }

  const plan = await db.prepare(`SELECT id,code,name,version,NULL AS feature_names FROM license_plans
    WHERE code=? AND plan_domain='COMMERCIAL_SAAS' AND status='ACTIVE' AND datetime(effective_from) <= datetime(?)
      AND (effective_to IS NULL OR datetime(effective_to) > datetime(?))
    ORDER BY version DESC LIMIT 1`).bind(signup.plan_code, now, now).first<SignupPlanRow>();
  if (!plan) {
    throw new SignupValidationError([{ code: "PLAN_UNAVAILABLE", path: "/plan_code", message: "The selected licence plan is not currently available for signup." }]);
  }

  const canonical = await db.prepare(`SELECT id FROM taxpayers WHERE vat_number=? OR tin=?
    UNION SELECT taxpayer_id AS id FROM taxpayer_identifiers
      WHERE country='NA' AND ((identifier_type='VAT_NUMBER' AND identifier_value=?) OR (identifier_type='TIN' AND identifier_value=?))
    LIMIT 1`).bind(signup.vat_number, signup.tin, signup.vat_number, signup.tin).first<{ id: string }>();
  const controlled = canonical ? null : await db.prepare(`SELECT id FROM registration_applications
    WHERE (vat_number=? OR tin=?) AND status IN ('PENDING_VERIFICATION','UNDER_REVIEW','VERIFIED') LIMIT 1`)
    .bind(signup.vat_number, signup.tin).first<{ id: string }>();
  const pending = canonical || controlled ? null : await db.prepare(`SELECT id FROM self_serve_signup_applications
    WHERE (vat_number=? OR tin=?) AND status IN ${ACTIVE_SIGNUP_STATES} LIMIT 1`)
    .bind(signup.vat_number, signup.tin).first<{ id: string }>();
  if (canonical || controlled || pending) {
    throw new RepositoryConflictError("A pending or existing application already covers the supplied taxpayer identity.");
  }

  const id = crypto.randomUUID();
  const publicReference = `VMS-${now.slice(0, 4)}-${crypto.randomUUID().replaceAll("-", "").slice(0, 10).toUpperCase()}`;
  const identityStatus = identity ? "EXTERNALLY_ASSERTED" : "VERIFICATION_REQUIRED";
  const outboxId = crypto.randomUUID();
  const auditId = crypto.randomUUID();
  const actorHash = identitySubjectHash ?? `sha256:${await sha256Hex(signup.contact_email)}`;
  const actorId = `self-serve:${actorHash.slice(-24)}`;
  const priorAudit = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const auditDetails = JSON.stringify({
    applicationReference: publicReference,
    correlationId,
    identityStatus,
    requestedPlanCode: plan.code,
    activationEffect: "NONE",
  });
  const auditHash = await sha256Hex(`${priorAudit?.event_hash ?? "GENESIS"}|${auditId}|${actorId}|${auditDetails}|${now}`);

  try {
    await db.batch([
      db.prepare(`INSERT INTO self_serve_signup_applications
        (id,public_reference,idempotency_key,request_hash,applicant_name,applicant_role,contact_email,
         identity_provider,identity_subject_hash,onboarding_path,country_code,requested_plan_id,vat_number,tin,
         company_registration_number,legal_name,trading_name,taxpayer_type,return_frequency,address,
         terms_version,privacy_notice_version,authority_attested_at,terms_accepted_at,privacy_notice_accepted_at,
         status,identity_status,taxpayer_verification_status,licence_status,promoted_registration_application_id,submitted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,?)`).bind(
        id, publicReference, idempotencyKey, requestHash, signup.applicant_name, signup.applicant_role,
        signup.contact_email, identity?.provider ?? null, identitySubjectHash, "COMPANY_ADMIN", signup.country_code, plan.id,
        signup.vat_number, signup.tin, signup.company_registration_number ?? null, signup.legal_name,
        signup.trading_name ?? null, signup.taxpayer_type, signup.return_frequency, signup.address,
        SELF_SERVE_TERMS_VERSION, SELF_SERVE_PRIVACY_NOTICE_VERSION, now, now, now,
        "PENDING_VERIFICATION", identityStatus, "AWAITING_PROVIDER_CONTRACT", "NOT_ACTIVATED", now,
      ),
      db.prepare(`INSERT INTO outbox_events
        (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
        outboxId, "SELF_SERVE_SIGNUP", id, "SelfServeSignupSubmitted", 1, actorHash,
        JSON.stringify({
          application_reference: publicReference,
          requested_plan_code: plan.code,
          status: "PENDING_VERIFICATION",
          identity_status: identityStatus,
          activation_effect: "NONE",
          correlation_id: correlationId,
        }),
        "PENDING", 0, now, now, null, null,
      ),
      db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(
        auditId, actorId, "SELF_SERVE_APPLICANT", "SELF_SERVE_SIGNUP_SUBMITTED", "SELF_SERVE_SIGNUP", id,
        "SUCCESS", auditDetails, priorAudit?.event_hash ?? null, auditHash, now,
      ),
    ]);
  } catch (cause) {
    const message = cause instanceof Error ? cause.message : "";
    if (/duplicate|unique|already covers|canonical taxpayer|controlled registration/i.test(message)) {
      throw new RepositoryConflictError("A pending or existing application already covers the supplied taxpayer identity.");
    }
    throw cause;
  }

  return {
    application_reference: publicReference,
    status: "PENDING_VERIFICATION",
    identity_status: identityStatus,
    taxpayer_verification_status: "AWAITING_PROVIDER_CONTRACT",
    licence_status: "NOT_ACTIVATED",
    submitted_at: now,
    next_action: "The commercial application is held for administrator and organisation verification. No account, payment, subscription or licence has been activated.",
  };
}
