import { ensureDatabase } from "@/db/runtime";
import { isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import { IdentityValidationError, normalizeAndValidateRegistration, type RegistrationSubmission } from "@/lib/domain/identity";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { getItasIdentityPort } from "@/lib/integrations/itas";
import { RepositoryConflictError } from "./repository";

export type OrganisationSummary = {
  id: string;
  taxpayer_id: string;
  legal_name: string;
  trading_name: string | null;
  vat_number: string;
  tin: string;
  vat_status: string;
  status: string;
  branch_count: number;
  member_count: number;
  capabilities: string;
};

export type RegistrationApplicationSummary = {
  id: string;
  vat_number: string;
  tin: string;
  company_registration_number: string | null;
  legal_name: string;
  trading_name: string | null;
  taxpayer_type: string;
  return_frequency: string;
  email: string;
  status: string;
  verification_source: string;
  verification_status: string | null;
  submitted_by: string;
  submitted_at: string;
};

type IdempotentRegistrationRow = RegistrationApplicationSummary & { request_hash: string };

const ORGANISATION_QUERY = `SELECT o.id, o.taxpayer_id, o.legal_name, o.trading_name,
  t.vat_number, t.tin, t.vat_status, o.status,
  (SELECT COUNT(*) FROM branches b WHERE b.organisation_id = o.id AND b.status = 'ACTIVE') AS branch_count,
  (SELECT COUNT(*) FROM organisation_memberships m WHERE m.organisation_id = o.id AND m.status = 'ACTIVE') AS member_count,
  COALESCE((SELECT GROUP_CONCAT(c.capability, ',') FROM organisation_capabilities c
    WHERE c.organisation_id = o.id AND c.status = 'ACTIVE' AND datetime(c.effective_from) <= CURRENT_TIMESTAMP
      AND (c.effective_to IS NULL OR datetime(c.effective_to) > CURRENT_TIMESTAMP)), '') AS capabilities
  FROM organisations o JOIN taxpayers t ON t.id = o.taxpayer_id`;

export async function listOrganisations(user: UserContext): Promise<OrganisationSummary[]> {
  const db = await ensureDatabase();
  const result = isNationalScope(user)
    ? await db.prepare(`${ORGANISATION_QUERY} ORDER BY o.legal_name`).all<OrganisationSummary>()
    : await db.prepare(`${ORGANISATION_QUERY} WHERE o.taxpayer_id = ? ORDER BY o.legal_name`)
      .bind(user.taxpayerId ?? "__none__").all<OrganisationSummary>();
  return result.results;
}

export async function getOrganisation(user: UserContext, organisationId: string) {
  const db = await ensureDatabase();
  const organisation = await db.prepare(`${ORGANISATION_QUERY} WHERE o.id = ?`).bind(organisationId).first<OrganisationSummary>();
  if (!organisation) return null;
  requireTaxpayerScope(user, organisation.taxpayer_id);
  const [branches, memberships, capabilities, identifiers] = await Promise.all([
    db.prepare("SELECT id, code, name, address, status, is_head_office FROM branches WHERE organisation_id = ? ORDER BY is_head_office DESC, name")
      .bind(organisationId).all<Record<string, string | number>>(),
    db.prepare(`SELECT m.id, u.display_name, u.email, m.role_code, m.branch_id, m.status, m.valid_from, m.valid_to
      FROM organisation_memberships m JOIN app_users u ON u.id = m.user_id
      WHERE m.organisation_id = ? ORDER BY u.display_name`).bind(organisationId).all<Record<string, string | null>>(),
    db.prepare("SELECT capability, status, effective_from, effective_to FROM organisation_capabilities WHERE organisation_id = ? ORDER BY capability")
      .bind(organisationId).all<Record<string, string | null>>(),
    db.prepare("SELECT identifier_type, identifier_value, country, status, source, verified_at FROM taxpayer_identifiers WHERE taxpayer_id = ? ORDER BY identifier_type")
      .bind(organisation.taxpayer_id).all<Record<string, string | null>>(),
  ]);
  return { ...organisation, branches: branches.results, memberships: memberships.results, capabilities: capabilities.results, identifiers: identifiers.results };
}

export async function listRegistrationApplications(user: UserContext): Promise<RegistrationApplicationSummary[]> {
  const db = await ensureDatabase();
  const base = `SELECT r.id,r.vat_number,r.tin,r.company_registration_number,r.legal_name,r.trading_name,
    r.taxpayer_type,r.return_frequency,r.email,r.status,r.verification_source,r.submitted_by,r.submitted_at,
    (SELECT v.status FROM registration_verifications v WHERE v.registration_application_id=r.id ORDER BY v.checked_at DESC LIMIT 1) AS verification_status
    FROM registration_applications r`;
  const result = isNationalScope(user)
    ? await db.prepare(`${base} ORDER BY r.submitted_at DESC LIMIT 100`).all<RegistrationApplicationSummary>()
    : await db.prepare(`${base} WHERE r.submitted_by = ? ORDER BY r.submitted_at DESC LIMIT 100`).bind(user.userId).all<RegistrationApplicationSummary>();
  return result.results;
}

export async function getIdentityFoundationSnapshot(user: UserContext) {
  const db = await ensureDatabase();
  const [providers, organisations, registrations, access] = await Promise.all([
    db.prepare(`SELECT provider_key,display_name,provider_type,authority_level,status,configuration_status,updated_at
      FROM identity_providers ORDER BY CASE provider_key WHEN 'ITAS' THEN 1 WHEN 'SITES_WORKSPACE' THEN 2 ELSE 3 END`)
      .all<Record<string, string | null>>(),
    listOrganisations(user),
    listRegistrationApplications(user),
    isNationalScope(user)
      ? db.prepare(`SELECT
          (SELECT COUNT(*) FROM app_users WHERE status='ACTIVE') AS active_users,
          (SELECT COUNT(*) FROM identity_links WHERE status='ACTIVE') AS active_identity_links,
          (SELECT COUNT(*) FROM organisation_memberships WHERE status='ACTIVE') AS active_memberships,
          (SELECT COUNT(*) FROM branches WHERE status='ACTIVE') AS active_branches`).first<Record<string, number>>()
      : db.prepare(`SELECT
          (SELECT COUNT(*) FROM app_users u WHERE u.status='ACTIVE' AND u.taxpayer_id=?) AS active_users,
          (SELECT COUNT(*) FROM identity_links l JOIN app_users u ON u.id=l.user_id WHERE l.status='ACTIVE' AND u.taxpayer_id=?) AS active_identity_links,
          (SELECT COUNT(*) FROM organisation_memberships m JOIN organisations o ON o.id=m.organisation_id WHERE m.status='ACTIVE' AND o.taxpayer_id=?) AS active_memberships,
          (SELECT COUNT(*) FROM branches b JOIN organisations o ON o.id=b.organisation_id WHERE b.status='ACTIVE' AND o.taxpayer_id=?) AS active_branches`)
        .bind(user.taxpayerId, user.taxpayerId, user.taxpayerId, user.taxpayerId).first<Record<string, number>>(),
  ]);
  return { providers: providers.results, organisations, registrations, access: access ?? {}, itas: await getItasIdentityPort().status() };
}

export async function submitRegistrationApplication(
  payload: RegistrationSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
): Promise<RegistrationApplicationSummary> {
  if (idempotencyKey.length < 16 || idempotencyKey.length > 128) {
    throw new IdentityValidationError([{ code: "IDEMPOTENCY_KEY_INVALID", path: "/headers/idempotency-key", message: "Idempotency key must contain 16 to 128 characters." }]);
  }
  const registration = normalizeAndValidateRegistration(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify(registration));
  const prior = await db.prepare(`SELECT r.*,
    (SELECT v.status FROM registration_verifications v WHERE v.registration_application_id=r.id ORDER BY v.checked_at DESC LIMIT 1) AS verification_status
    FROM registration_applications r WHERE r.submitted_by=? AND r.idempotency_key=?`)
    .bind(actor.userId, idempotencyKey).first<IdempotentRegistrationRow>();
  if (prior) {
    if (prior.request_hash !== requestHash) throw new RepositoryConflictError("The idempotency key was already used for a different registration application.");
    return prior;
  }

  const registered = await db.prepare(`SELECT id FROM taxpayers WHERE vat_number=? OR tin=?
    UNION SELECT taxpayer_id AS id FROM taxpayer_identifiers WHERE (identifier_type='VAT_NUMBER' AND identifier_value=?) OR (identifier_type='TIN' AND identifier_value=?) LIMIT 1`)
    .bind(registration.vat_number, registration.tin, registration.vat_number, registration.tin).first<{ id: string }>();
  if (registered) throw new RepositoryConflictError("A canonical taxpayer already exists for the supplied VAT number or TIN.");

  const duplicate = await db.prepare(`SELECT id FROM registration_applications
    WHERE (vat_number=? OR tin=?) AND status IN ('PENDING_VERIFICATION','UNDER_REVIEW','VERIFIED') LIMIT 1`)
    .bind(registration.vat_number, registration.tin).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`An active registration application already exists as ${duplicate.id}.`);

  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const verificationId = crypto.randomUUID();
  const verificationReference = `itas-contract-pending:${id}`;
  const outboxId = crypto.randomUUID();
  const auditId = crypto.randomUUID();
  const priorAudit = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const auditDetails = JSON.stringify({ registrationId: id, vatNumber: registration.vat_number, correlationId, verificationState: "AWAITING_PROVIDER_CONTRACT" });
  const auditHash = await sha256Hex(`${priorAudit?.event_hash ?? "GENESIS"}|${auditId}|${actor.userId}|${auditDetails}|${now}`);

  await db.batch([
    db.prepare(`INSERT INTO registration_applications
      (id,idempotency_key,request_hash,vat_number,tin,company_registration_number,legal_name,trading_name,taxpayer_type,return_frequency,address,email,status,verification_source,submitted_by,submitted_at,reviewed_at,review_reason)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)`).bind(
        id, idempotencyKey, requestHash, registration.vat_number, registration.tin,
        registration.company_registration_number ?? null, registration.legal_name, registration.trading_name ?? null,
        registration.taxpayer_type, registration.return_frequency, registration.address, registration.email,
        "PENDING_VERIFICATION", "ITAS", actor.userId, now,
      ),
    db.prepare(`INSERT INTO registration_verifications
      (id,registration_application_id,provider,request_reference,status,response_hash,verified_taxpayer_id,checked_at,expires_at)
      VALUES (?,?,?,?,?,NULL,NULL,?,NULL)`).bind(verificationId, id, "ITAS", verificationReference, "AWAITING_PROVIDER_CONTRACT", now),
    db.prepare(`INSERT INTO outbox_events
      (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
        outboxId, "REGISTRATION", id, "TaxpayerRegistrationSubmitted", 1, registration.vat_number,
        JSON.stringify({ registration_id: id, status: "PENDING_VERIFICATION", correlation_id: correlationId }),
        "PENDING", 0, now, now, null, null,
      ),
    db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(
      auditId, actor.userId, actor.role, "TAXPAYER_REGISTRATION_SUBMITTED", "REGISTRATION", id,
      "SUCCESS", auditDetails, priorAudit?.event_hash ?? null, auditHash, now,
    ),
  ]);

  return {
    id,
    vat_number: registration.vat_number,
    tin: registration.tin,
    company_registration_number: registration.company_registration_number ?? null,
    legal_name: registration.legal_name,
    trading_name: registration.trading_name ?? null,
    taxpayer_type: registration.taxpayer_type,
    return_frequency: registration.return_frequency,
    email: registration.email,
    status: "PENDING_VERIFICATION",
    verification_source: "ITAS",
    verification_status: "AWAITING_PROVIDER_CONTRACT",
    submitted_by: actor.userId,
    submitted_at: now,
  };
}
