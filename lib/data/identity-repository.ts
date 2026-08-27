import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import {
  IdentityValidationError,
  normalizeAndValidateRegistration,
  normalizeBranch,
  normalizeBranchUpdate,
  normalizeCounterpartyVatNumber,
  normalizeIdentifierCorrection,
  normalizeIdentityLink,
  normalizeInvitationClaim,
  normalizeMembershipAssignment,
  normalizeRegistrationDecision,
  normalizeTaxpayerSuspension,
  normalizeUserInvitation,
  normalizeUserSuspension,
  type RegistrationSubmission,
} from "@/lib/domain/identity";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { getItasIdentityPort, ItasIntegrationUnavailableError } from "@/lib/integrations/itas";
import { RepositoryConflictError } from "./repository";

/** Module 10 Phase B: the ITAS anti-corruption-layer contract version this codebase is built against — tracked so TaxpayerVerified's "source_version" (event-catalog.csv) has a real, versioned value rather than a placeholder, and so a future contract revision has one place to bump. */
const ITAS_CONTRACT_VERSION = "1.0";

/** Module 8 Phase D: delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, new Date().toISOString());
}

function outboxEvent(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>) {
  const now = new Date().toISOString();
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

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
    db.prepare("SELECT id, identifier_type, identifier_value, country, status, source, verified_at, version, effective_from, effective_to FROM taxpayer_identifiers WHERE taxpayer_id = ? ORDER BY identifier_type, version DESC")
      .bind(organisation.taxpayer_id).all<Record<string, string | number | null>>(),
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
  return { providers: providers.results, organisations, registrations, access: access ?? {}, itas: await getItasIdentityPort(db).status() };
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
  const outboxId = crypto.randomUUID();

  // Module 10 Phase B: this used to hardcode AWAITING_PROVIDER_CONTRACT
  // directly, never actually going through ItasIdentityPort at all -- a
  // registration intake could never reflect a real ITAS outcome even once
  // the sandbox/mock adapter is reachable. Now genuinely attempts the same
  // verifyTaxpayer call verifyTaxpayerIdentifiers makes, with the identical
  // fail-closed catch below.
  const itas = getItasIdentityPort(db);
  let verificationStatus: "VERIFIED" | "AWAITING_PROVIDER_CONTRACT" = "AWAITING_PROVIDER_CONTRACT";
  let verificationReference = `itas-verify:${id}`;
  let responseHash: string | null = null;
  let checkedAt = now;
  let expiresAt: string | null = null;
  try {
    const result = await itas.verifyTaxpayer({ vatNumber: registration.vat_number, tin: registration.tin, companyRegistrationNumber: registration.company_registration_number, correlationId });
    verificationStatus = "VERIFIED";
    verificationReference = result.requestReference;
    responseHash = result.responseHash;
    checkedAt = result.checkedAt;
    expiresAt = result.expiresAt ?? null;
    // registration_verifications.verified_taxpayer_id is a FK to an *existing* taxpayers row — it is for
    // ITAS matching this application to an already-on-file taxpayer (e.g. a duplicate detection signal),
    // never for the not-yet-created applicant itself, so it deliberately stays NULL at intake regardless
    // of what a provider's own response claims — result.authoritativeTaxpayerId is not a real row here.
  } catch (error) {
    if (!(error instanceof ItasIntegrationUnavailableError)) throw error;
  }

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
      VALUES (?,?,?,?,?,?,NULL,?,?)`).bind(verificationId, id, "ITAS", verificationReference, verificationStatus, responseHash, checkedAt, expiresAt),
    db.prepare(`INSERT INTO outbox_events
      (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
        outboxId, "REGISTRATION", id, "TaxpayerRegistrationSubmitted", 1, registration.vat_number,
        JSON.stringify({ registration_id: id, status: "PENDING_VERIFICATION", correlation_id: correlationId }),
        "PENDING", 0, now, now, null, null,
      ),
    await appendAuditEvent(db, actor, "TAXPAYER_REGISTRATION_SUBMITTED", "REGISTRATION", id, { registrationId: id, vatNumber: registration.vat_number, correlationId, verificationState: verificationStatus }, now),
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
    verification_status: verificationStatus,
    submitted_by: actor.userId,
    submitted_at: now,
  };
}

type RegistrationRow = {
  id: string;
  vat_number: string;
  tin: string;
  legal_name: string;
  trading_name: string | null;
  taxpayer_type: string;
  return_frequency: string;
  address: string;
  email: string;
  status: string;
  submitted_by: string;
};

export type RegistrationDecisionResult = {
  registrationId: string;
  status: "APPROVED" | "REJECTED";
  taxpayerId: string | null;
  organisationId: string | null;
};

/**
 * The standalone (non-ITAS) registration approval path: an authorised NamRA
 * or pilot-admin officer reviews a pending registration application. This is
 * Module 1's ActivateOrganisation + EnableCapability, combined into one
 * command because they only ever make sense together at this boundary —
 * approving materializes the taxpayer, organisation, head-office branch,
 * buyer/seller capabilities and the submitter's owner membership in one
 * atomic write; rejecting leaves no trace beyond the registration record.
 */
export async function decideRegistrationApplication(
  actor: UserContext,
  registrationId: string,
  input: unknown,
  correlationId: string,
): Promise<RegistrationDecisionResult> {
  const { decision, reason } = normalizeRegistrationDecision(input);
  const db = await ensureDatabase();
  const registration = await db.prepare(`SELECT id,vat_number,tin,legal_name,trading_name,taxpayer_type,return_frequency,address,email,status,submitted_by
    FROM registration_applications WHERE id=?`).bind(registrationId).first<RegistrationRow>();
  if (!registration) {
    throw new IdentityValidationError([{ code: "REGISTRATION_NOT_FOUND", path: "/registration_id", message: "The registration application does not exist." }]);
  }
  if (!["PENDING_VERIFICATION", "UNDER_REVIEW", "VERIFIED"].includes(registration.status)) {
    throw new IdentityValidationError([{ code: "REGISTRATION_NOT_PENDING", path: "/status", message: `The registration application is already ${registration.status}.` }]);
  }
  if (actor.userId === registration.submitted_by) {
    throw new IdentityValidationError([{ code: "SELF_APPROVAL_DENIED", path: "/actor", message: "The submitting user cannot decide their own registration application." }]);
  }

  const now = new Date().toISOString();
  const verificationId = crypto.randomUUID();

  if (decision === "REJECT") {
    await db.batch([
      db.prepare("UPDATE registration_applications SET status='REJECTED',reviewed_at=?,review_reason=? WHERE id=?").bind(now, reason, registration.id),
      db.prepare(`INSERT INTO registration_verifications
        (id,registration_application_id,provider,request_reference,status,response_hash,verified_taxpayer_id,checked_at,expires_at)
        VALUES (?,?,?,?,?,NULL,NULL,?,NULL)`).bind(verificationId, registration.id, "MANUAL_REVIEW", `manual:${registration.id}`, "REJECTED", now),
      outboxEvent(db, "REGISTRATION", registration.id, "TaxpayerRegistrationRejected", registration.vat_number, { registration_id: registration.id, reason, correlation_id: correlationId }),
      await appendAudit(db, actor, "TAXPAYER_REGISTRATION_REJECTED", "REGISTRATION", registration.id, { reason }),
    ]);
    return { registrationId: registration.id, status: "REJECTED", taxpayerId: null, organisationId: null };
  }

  const conflict = await db.prepare(`SELECT id FROM taxpayers WHERE vat_number=? OR tin=?
    UNION SELECT taxpayer_id AS id FROM taxpayer_identifiers WHERE (identifier_type='VAT_NUMBER' AND identifier_value=?) OR (identifier_type='TIN' AND identifier_value=?) LIMIT 1`)
    .bind(registration.vat_number, registration.tin, registration.vat_number, registration.tin).first<{ id: string }>();
  if (conflict) throw new RepositoryConflictError("A canonical taxpayer already exists for this VAT number or TIN.");

  const taxpayerId = crypto.randomUUID();
  const organisationId = crypto.randomUUID();
  const branchId = crypto.randomUUID();
  const membershipId = crypto.randomUUID();

  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind(
        taxpayerId, registration.vat_number, registration.tin, registration.legal_name, registration.trading_name,
        registration.taxpayer_type, "ACTIVE", registration.return_frequency, registration.address, registration.email, now,
      ),
    db.prepare(`INSERT INTO taxpayer_identifiers (id,taxpayer_id,identifier_type,identifier_value,country,status,source,verified_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), taxpayerId, "VAT_NUMBER", registration.vat_number, "NA", "ACTIVE", "MANUAL_REVIEW", now, now),
    db.prepare(`INSERT INTO taxpayer_identifiers (id,taxpayer_id,identifier_type,identifier_value,country,status,source,verified_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), taxpayerId, "TIN", registration.tin, "NA", "ACTIVE", "MANUAL_REVIEW", now, now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?)`).bind(organisationId, taxpayerId, registration.legal_name, registration.trading_name, "ACTIVE", now, now),
    db.prepare(`INSERT INTO branches (id,organisation_id,code,name,address,status,is_head_office,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(branchId, organisationId, "HEAD", `${registration.legal_name} Head Office`, registration.address, "ACTIVE", 1, now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind(crypto.randomUUID(), organisationId, "BUYER", "ACTIVE", now, actor.userId, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind(crypto.randomUUID(), organisationId, "SELLER", "ACTIVE", now, actor.userId, now),
    db.prepare(`INSERT INTO organisation_memberships (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
      VALUES (?,?,?,?,?,?,?,NULL,?,?)`).bind(membershipId, organisationId, registration.submitted_by, "TAXPAYER_OWNER", branchId, "ACTIVE", now, actor.userId, now),
    db.prepare("UPDATE app_users SET role='TAXPAYER_OWNER',taxpayer_id=? WHERE id=? AND taxpayer_id IS NULL").bind(taxpayerId, registration.submitted_by),
    db.prepare("UPDATE registration_applications SET status='APPROVED',reviewed_at=?,review_reason=? WHERE id=?").bind(now, reason, registration.id),
    db.prepare(`INSERT INTO registration_verifications
      (id,registration_application_id,provider,request_reference,status,response_hash,verified_taxpayer_id,checked_at,expires_at)
      VALUES (?,?,?,?,?,NULL,?,?,NULL)`).bind(verificationId, registration.id, "MANUAL_REVIEW", `manual:${registration.id}`, "VERIFIED", taxpayerId, now),
    outboxEvent(db, "ORGANISATION", organisationId, "OrganisationActivated", registration.vat_number, { organisation_id: organisationId, taxpayer_id: taxpayerId, registration_id: registration.id, correlation_id: correlationId }),
    await appendAudit(db, actor, "TAXPAYER_REGISTRATION_APPROVED", "REGISTRATION", registration.id, { reason, taxpayerId, organisationId }),
  ]);

  return { registrationId: registration.id, status: "APPROVED", taxpayerId, organisationId };
}

export type MembershipAssignmentResult = {
  id: string;
  organisationId: string;
  userId: string;
  roleCode: string;
  branchId: string | null;
  status: string;
};

/**
 * Module 1 AssignMembership: an organisation admin (or NamRA) grants an
 * existing, already-provisioned app_users row a taxpayer-side membership in
 * one of their organisations. Deliberately does not provision a brand-new
 * user identity — for someone with no app_users row yet, use inviteUser /
 * claimInvitation (ProvisionUser) below instead.
 */
export async function assignMembership(
  actor: UserContext,
  organisationId: string,
  input: unknown,
  correlationId: string,
): Promise<MembershipAssignmentResult> {
  const assignment = normalizeMembershipAssignment(input);
  const db = await ensureDatabase();
  const organisation = await requireOrganisationInScope(db, actor, organisationId);

  const targetUser = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(assignment.userId).first<{ id: string; status: string }>();
  if (!targetUser) {
    throw new IdentityValidationError([{ code: "USER_NOT_FOUND", path: "/user_id", message: "The target user does not exist." }]);
  }
  if (targetUser.status !== "ACTIVE") {
    throw new IdentityValidationError([{ code: "USER_NOT_ACTIVE", path: "/user_id", message: "The target user is not active." }]);
  }
  if (assignment.branchId) {
    const branch = await db.prepare("SELECT id FROM branches WHERE id=? AND organisation_id=?").bind(assignment.branchId, organisationId).first<{ id: string }>();
    if (!branch) throw new IdentityValidationError([{ code: "BRANCH_OUT_OF_SCOPE", path: "/branch_id", message: "The branch is outside this organisation." }]);
  }
  const existing = await db.prepare("SELECT id FROM organisation_memberships WHERE organisation_id=? AND user_id=? AND status='ACTIVE'")
    .bind(organisationId, assignment.userId).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError("The user already has an active membership in this organisation.");

  const now = new Date().toISOString();
  const membershipId = crypto.randomUUID();
  await db.batch([
    db.prepare(`INSERT INTO organisation_memberships (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
      VALUES (?,?,?,?,?,?,?,NULL,?,?)`).bind(membershipId, organisationId, assignment.userId, assignment.roleCode, assignment.branchId, "ACTIVE", now, actor.userId, now),
    db.prepare("UPDATE app_users SET taxpayer_id=? WHERE id=? AND taxpayer_id IS NULL").bind(organisation.taxpayer_id, assignment.userId),
    outboxEvent(db, "ORGANISATION", organisationId, "OrganisationMembershipAssigned", organisation.taxpayer_id, { organisation_id: organisationId, user_id: assignment.userId, role_code: assignment.roleCode, correlation_id: correlationId }),
    await appendAudit(db, actor, "MEMBERSHIP_ASSIGNED", "ORGANISATION_MEMBERSHIP", membershipId, { organisationId, userId: assignment.userId, roleCode: assignment.roleCode }),
  ]);

  return { id: membershipId, organisationId, userId: assignment.userId, roleCode: assignment.roleCode, branchId: assignment.branchId, status: "ACTIVE" };
}

export type TaxpayerSuspensionResult = { taxpayerId: string; vatStatus: string };

/**
 * Module 1 SuspendTaxpayer. Flips taxpayers.vat_status to SUSPENDED, which
 * already has real enforcement effect elsewhere (lib/data/repository.ts
 * resolves invoice counterparties and lists taxpayers filtered on
 * vat_status='ACTIVE') — no further wiring needed for suspension to take
 * hold. Idempotent: suspending an already-suspended taxpayer is a no-op
 * that returns the current state rather than erroring.
 */
export async function suspendTaxpayer(
  actor: UserContext,
  taxpayerId: string,
  input: unknown,
  correlationId: string,
): Promise<TaxpayerSuspensionResult> {
  const { reason } = normalizeTaxpayerSuspension(input);
  const db = await ensureDatabase();
  const taxpayer = await db.prepare("SELECT id,vat_status FROM taxpayers WHERE id=?").bind(taxpayerId).first<{ id: string; vat_status: string }>();
  if (!taxpayer) {
    throw new IdentityValidationError([{ code: "TAXPAYER_NOT_FOUND", path: "/taxpayer_id", message: "The taxpayer does not exist." }]);
  }
  if (taxpayer.vat_status === "SUSPENDED") {
    return { taxpayerId: taxpayer.id, vatStatus: "SUSPENDED" };
  }
  await db.batch([
    db.prepare("UPDATE taxpayers SET vat_status='SUSPENDED' WHERE id=?").bind(taxpayerId),
    outboxEvent(db, "TAXPAYER", taxpayerId, "TaxpayerSuspended", taxpayerId, { taxpayer_id: taxpayerId, reason, correlation_id: correlationId }),
    await appendAudit(db, actor, "TAXPAYER_SUSPENDED", "TAXPAYER", taxpayerId, { reason, previousStatus: taxpayer.vat_status }),
  ]);
  return { taxpayerId, vatStatus: "SUSPENDED" };
}

type OrganisationScopeRow = { id: string; taxpayer_id: string };

async function requireOrganisationInScope(db: D1Database, actor: UserContext, organisationId: string): Promise<OrganisationScopeRow> {
  const organisation = await db.prepare("SELECT id,taxpayer_id FROM organisations WHERE id=?").bind(organisationId).first<OrganisationScopeRow>();
  if (!organisation) {
    throw new IdentityValidationError([{ code: "ORGANISATION_NOT_FOUND", path: "/organisation_id", message: "The organisation does not exist." }]);
  }
  requireTaxpayerScope(actor, organisation.taxpayer_id);
  return organisation;
}

export type BranchSummary = { id: string; code: string; name: string; address: string; status: string; is_head_office: number };

/** Module 1 ListBranches, as its own standalone query — previously branches were only readable nested inside GetOrganisation. */
export async function listBranches(actor: UserContext, organisationId: string): Promise<BranchSummary[]> {
  const db = await ensureDatabase();
  await requireOrganisationInScope(db, actor, organisationId);
  const result = await db.prepare("SELECT id,code,name,address,status,is_head_office FROM branches WHERE organisation_id=? ORDER BY is_head_office DESC, name")
    .bind(organisationId).all<BranchSummary>();
  return result.results;
}

export type BranchResult = { id: string; organisationId: string; code: string; name: string; address: string; status: string; isHeadOffice: boolean };

export async function createBranch(actor: UserContext, organisationId: string, input: unknown, correlationId: string): Promise<BranchResult> {
  const branch = normalizeBranch(input);
  const db = await ensureDatabase();
  const organisation = await requireOrganisationInScope(db, actor, organisationId);
  const duplicate = await db.prepare("SELECT id FROM branches WHERE organisation_id=? AND code=?").bind(organisationId, branch.code).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`A branch with code ${branch.code} already exists for this organisation.`);
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare(`INSERT INTO branches (id,organisation_id,code,name,address,status,is_head_office,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(id, organisationId, branch.code, branch.name, branch.address, "ACTIVE", 0, now, now),
    outboxEvent(db, "ORGANISATION", organisationId, "BranchCreated", organisation.taxpayer_id, { organisation_id: organisationId, branch_id: id, code: branch.code, correlation_id: correlationId }),
    await appendAudit(db, actor, "BRANCH_CREATED", "BRANCH", id, { organisationId, code: branch.code }),
  ]);
  return { id, organisationId, code: branch.code, name: branch.name, address: branch.address, status: "ACTIVE", isHeadOffice: false };
}

export async function updateBranch(
  actor: UserContext,
  organisationId: string,
  branchId: string,
  input: unknown,
  correlationId: string,
): Promise<BranchResult> {
  const update = normalizeBranchUpdate(input);
  const db = await ensureDatabase();
  const organisation = await requireOrganisationInScope(db, actor, organisationId);
  const branch = await db.prepare("SELECT id,code,name,address,status,is_head_office FROM branches WHERE id=? AND organisation_id=?")
    .bind(branchId, organisationId).first<{ id: string; code: string; name: string; address: string; status: string; is_head_office: number }>();
  if (!branch) {
    throw new IdentityValidationError([{ code: "BRANCH_NOT_FOUND", path: "/branch_id", message: "The branch is outside this organisation." }]);
  }
  if (update.status === "INACTIVE" && branch.is_head_office) {
    throw new IdentityValidationError([{ code: "HEAD_OFFICE_CANNOT_DEACTIVATE", path: "/status", message: "The head office branch cannot be deactivated." }]);
  }
  const name = update.name ?? branch.name;
  const address = update.address ?? branch.address;
  const status = update.status ?? branch.status;
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE branches SET name=?,address=?,status=?,updated_at=? WHERE id=? AND organisation_id=?").bind(name, address, status, now, branchId, organisationId),
    outboxEvent(db, "ORGANISATION", organisationId, "BranchUpdated", organisation.taxpayer_id, { organisation_id: organisationId, branch_id: branchId, changes: update, correlation_id: correlationId }),
    await appendAudit(db, actor, "BRANCH_UPDATED", "BRANCH", branchId, { organisationId, changes: update }),
  ]);
  return { id: branchId, organisationId, code: branch.code, name, address, status, isHeadOffice: Boolean(branch.is_head_office) };
}

export type TransactionClassification = {
  vatNumber: string;
  taxpayerActive: boolean;
  organisationActive: boolean;
  capabilities: string[];
  canActAsSeller: boolean;
  canActAsBuyer: boolean;
};

/**
 * Module 1 Buyer/Seller ClassifyTransaction: a pre-flight check for whether
 * a VAT number would resolve as a valid transaction counterparty, using the
 * exact same taxpayer/organisation/capability resolution rules invoice
 * certification already enforces (lib/data/repository.ts's supplier/customer
 * resolution) — single-sourced here rather than duplicated. Reveals nothing
 * a caller couldn't already learn indirectly from a rejected invoice
 * submission, so no tenant scoping is required: this is a cross-tenant,
 * public-posture lookup by design, not a privilege boundary.
 */
export async function classifyTransaction(vatNumberInput: unknown): Promise<TransactionClassification> {
  const vatNumber = normalizeCounterpartyVatNumber(vatNumberInput);
  const db = await ensureDatabase();
  const taxpayer = await db.prepare("SELECT id FROM taxpayers WHERE vat_number=? AND vat_status='ACTIVE'").bind(vatNumber).first<{ id: string }>();
  if (!taxpayer) {
    return { vatNumber, taxpayerActive: false, organisationActive: false, capabilities: [], canActAsSeller: false, canActAsBuyer: false };
  }
  const organisation = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND status='ACTIVE'").bind(taxpayer.id).first<{ id: string }>();
  if (!organisation) {
    return { vatNumber, taxpayerActive: true, organisationActive: false, capabilities: [], canActAsSeller: false, canActAsBuyer: false };
  }
  const active = await db.prepare(`SELECT capability FROM organisation_capabilities
    WHERE organisation_id=? AND status='ACTIVE'
      AND datetime(effective_from)<=CURRENT_TIMESTAMP AND (effective_to IS NULL OR datetime(effective_to)>CURRENT_TIMESTAMP)`)
    .bind(organisation.id).all<{ capability: string }>();
  const capabilities = active.results.map((row) => row.capability);
  return {
    vatNumber,
    taxpayerActive: true,
    organisationActive: true,
    capabilities,
    canActAsSeller: capabilities.includes("SELLER"),
    canActAsBuyer: capabilities.includes("BUYER"),
  };
}

export type IdentityLinkSummary = {
  id: string;
  providerKey: string;
  subject: string;
  assuranceLevel: string;
  status: string;
  linkedAt: string;
  lastAuthenticatedAt: string | null;
};

/** Module 1 ResolveIdentity: list identity links for a user — self by default, or an admin-specified user_id (administration:manage checked at the route). */
export async function listIdentityLinks(userId: string): Promise<IdentityLinkSummary[]> {
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT l.id,p.provider_key,l.subject,l.assurance_level,l.status,l.linked_at,l.last_authenticated_at
    FROM identity_links l JOIN identity_providers p ON p.id=l.provider_id
    WHERE l.user_id=? ORDER BY l.linked_at`).bind(userId)
    .all<{ id: string; provider_key: string; subject: string; assurance_level: string; status: string; linked_at: string; last_authenticated_at: string | null }>();
  return result.results.map((row) => ({
    id: row.id, providerKey: row.provider_key, subject: row.subject, assuranceLevel: row.assurance_level,
    status: row.status, linkedAt: row.linked_at, lastAuthenticatedAt: row.last_authenticated_at,
  }));
}

/**
 * Module 1 LinkIdentity: administratively links an additional identity
 * provider subject to an existing, active app_users row. The provider must
 * already be ACTIVE and CONFIGURED — today that is only SITES_WORKSPACE;
 * attempting to link against ITAS or the standalone provider correctly
 * fails closed until their configuration_status becomes CONFIGURED, which
 * is a security/regulatory decision this command cannot grant itself.
 * Deliberately records assurance_level='ADMINISTRATIVE_LINK' rather than
 * letting the caller assert a stronger level this manual action didn't
 * actually establish.
 *
 * 2026-08-27 security fix: `administration:manage` is a tenant-grantable
 * permission (TAXPAYER_OWNER/TAXPAYER_ADMIN hold it for ordinary
 * organisation administration, e.g. inviteEmployee), but this command had
 * no scope check on the *target* user at all — a tenant admin could link a
 * platform subject they control to any app_users row, including a
 * national-scope account, and authenticate as it on the very next request.
 * A non-national actor may now only link an identity to a user within
 * their own taxpayer's organisation; a national-scope actor (the intended
 * "real" identity administrator, e.g. NAMRA_SYSTEM_ADMIN) is unrestricted,
 * matching resolveTaxpayer's own established scope pattern.
 */
export async function linkIdentity(actor: UserContext, input: unknown, correlationId: string): Promise<IdentityLinkSummary> {
  const link = normalizeIdentityLink(input);
  const db = await ensureDatabase();
  const targetUser = await db.prepare("SELECT id,status,taxpayer_id FROM app_users WHERE id=?").bind(link.userId).first<{ id: string; status: string; taxpayer_id: string | null }>();
  if (!targetUser) throw new IdentityValidationError([{ code: "USER_NOT_FOUND", path: "/user_id", message: "The target user does not exist." }]);
  if (targetUser.status !== "ACTIVE") throw new IdentityValidationError([{ code: "USER_NOT_ACTIVE", path: "/user_id", message: "The target user is not active." }]);
  if (!isNationalScope(actor) && (actor.taxpayerId == null || targetUser.taxpayer_id !== actor.taxpayerId)) {
    throw new AccessDeniedError("You may only link an identity to a user within your own taxpayer organisation.");
  }
  const provider = await db.prepare("SELECT id,status,configuration_status FROM identity_providers WHERE provider_key=?").bind(link.providerKey)
    .first<{ id: string; status: string; configuration_status: string }>();
  if (!provider) throw new IdentityValidationError([{ code: "PROVIDER_NOT_FOUND", path: "/provider_key", message: "The identity provider is not registered." }]);
  if (provider.status !== "ACTIVE" || provider.configuration_status !== "CONFIGURED") {
    throw new IdentityValidationError([{ code: "PROVIDER_NOT_CONFIGURED", path: "/provider_key", message: `${link.providerKey} is not yet configured for production linking (${provider.configuration_status}).` }]);
  }
  const existing = await db.prepare("SELECT id FROM identity_links WHERE provider_id=? AND subject=?").bind(provider.id, link.subject).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError("This provider subject is already linked to an identity.");

  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,NULL,?,?,?,NULL)`).bind(id, link.userId, provider.id, link.subject, "ADMINISTRATIVE_LINK", "ACTIVE", now),
    // Module 10 Phase B: payload now carries every field 08-enterprise-architecture/event-catalog.csv's
    // IdentityLinked row names as its minimum payload (user_id,provider_id,assurance,occurred_at) -- previously missing assurance/occurred_at entirely.
    outboxEvent(db, "IDENTITY", id, "IdentityLinked", link.userId, { userId: link.userId, providerId: provider.id, providerKey: link.providerKey, assurance: "ADMINISTRATIVE_LINK", occurredAt: now, correlationId }),
    await appendAudit(db, actor, "IDENTITY_LINKED", "IDENTITY_LINK", id, { userId: link.userId, providerKey: link.providerKey }),
  ]);
  return { id, providerKey: link.providerKey, subject: link.subject, assuranceLevel: "ADMINISTRATIVE_LINK", status: "ACTIVE", linkedAt: now, lastAuthenticatedAt: null };
}

/**
 * Module 1 RevokeSession — per MODULE_DEVELOPMENT_PLAYBOOK.md's Identity
 * Phase A decision: there is no separate session record this system
 * actually controls (no cookie, no JWT; the ChatGPT/OpenAI platform is the
 * real authentication authority), so "revoke a session" means revoke the
 * identity_link. This has a real, verifiable effect: getCurrentUser's join
 * requires identity_links.status='ACTIVE', so a revoked link stops
 * authenticating on its very next request. Admin-only: self-revocation in
 * a header-trust model with no separate login screen risks a lockout with
 * no recovery path.
 *
 * 2026-08-27 security fix: same missing-scope-check issue as linkIdentity
 * above — a tenant admin could otherwise revoke any other user's session
 * platform-wide, including a national-scope account's. Same fix: a
 * non-national actor may only revoke a link belonging to a user within
 * their own taxpayer's organisation.
 */
export async function revokeIdentityLink(actor: UserContext, identityLinkId: string, correlationId: string): Promise<{ id: string; status: string }> {
  const db = await ensureDatabase();
  const link = await db.prepare(`SELECT l.id,l.user_id,l.status,u.taxpayer_id FROM identity_links l JOIN app_users u ON u.id=l.user_id WHERE l.id=?`)
    .bind(identityLinkId).first<{ id: string; user_id: string; status: string; taxpayer_id: string | null }>();
  if (!link) throw new IdentityValidationError([{ code: "IDENTITY_LINK_NOT_FOUND", path: "/identity_link_id", message: "The identity link does not exist." }]);
  if (!isNationalScope(actor) && (actor.taxpayerId == null || link.taxpayer_id !== actor.taxpayerId)) {
    throw new AccessDeniedError("You may only revoke an identity link belonging to a user within your own taxpayer organisation.");
  }
  if (link.status === "REVOKED") return { id: link.id, status: "REVOKED" };
  await db.batch([
    db.prepare("UPDATE identity_links SET status='REVOKED' WHERE id=?").bind(link.id),
    outboxEvent(db, "IDENTITY", link.id, "SessionRevoked", link.user_id, { identityLinkId: link.id, userId: link.user_id, correlationId }),
    await appendAudit(db, actor, "SESSION_REVOKED", "IDENTITY_LINK", link.id, { userId: link.user_id }),
  ]);
  return { id: link.id, status: "REVOKED" };
}

const INVITATION_TTL_MS = 7 * 24 * 60 * 60 * 1000;

export type UserInvitationResult = {
  id: string;
  organisationId: string;
  email: string;
  roleCode: string;
  status: string;
  claimToken: string;
  expiresAt: string;
  invitationDelivery: string;
};

/**
 * Module 1 Identity ProvisionUser, invite half (see
 * MODULE_DEVELOPMENT_PLAYBOOK.md's Phase C decision: explicit invite-and-
 * claim, generalizing inviteEmployee's pattern beyond employees). Generates a
 * single-use claim token; nothing actually delivers it anywhere — this repo
 * has no outbound email integration, matching inviteEmployee's
 * DISABLED_LOCAL_STAGING delivery — so it is returned directly to the
 * inviting admin to relay out of band.
 */
export async function inviteUser(
  actor: UserContext,
  organisationId: string,
  input: unknown,
  correlationId: string,
): Promise<UserInvitationResult> {
  const invitation = normalizeUserInvitation(input);
  const db = await ensureDatabase();
  const organisation = await requireOrganisationInScope(db, actor, organisationId);

  const existingUser = await db.prepare("SELECT id FROM app_users WHERE lower(email)=lower(?)").bind(invitation.email).first<{ id: string }>();
  if (existingUser) throw new RepositoryConflictError("A user with this email already exists.");
  const pending = await db.prepare("SELECT id FROM user_invitations WHERE organisation_id=? AND lower(email)=lower(?) AND status='PENDING'")
    .bind(organisationId, invitation.email).first<{ id: string }>();
  if (pending) throw new RepositoryConflictError("A pending invitation already exists for this email in this organisation.");

  const now = new Date().toISOString();
  const expiresAt = new Date(Date.now() + INVITATION_TTL_MS).toISOString();
  const id = crypto.randomUUID();
  const claimToken = `${crypto.randomUUID()}${crypto.randomUUID()}`.replaceAll("-", "");
  await db.batch([
    db.prepare(`INSERT INTO user_invitations (id,organisation_id,email,role_code,claim_token,status,invited_by,invited_at,expires_at,claimed_at,claimed_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL)`).bind(id, organisationId, invitation.email, invitation.roleCode, claimToken, "PENDING", actor.userId, now, expiresAt),
    outboxEvent(db, "IDENTITY", id, "UserInvitationCreated", organisation.taxpayer_id, { invitationId: id, organisationId, email: invitation.email, delivery: "DISABLED_LOCAL_STAGING", correlationId }),
    await appendAudit(db, actor, "USER_INVITED", "USER_INVITATION", id, { organisationId, email: invitation.email, roleCode: invitation.roleCode }),
  ]);
  return { id, organisationId, email: invitation.email, roleCode: invitation.roleCode, status: "PENDING", claimToken, expiresAt, invitationDelivery: "DISABLED_LOCAL_STAGING" };
}

export type PlatformIdentity = { subject: string; email: string; displayName: string };

export type InvitationClaimResult = { userId: string; organisationId: string; roleCode: string; status: string };

/**
 * Module 1 Identity ProvisionUser, claim half. The caller has no app_users
 * row yet, so this cannot go through getCurrentUser()/requirePermission like
 * every other command in this file — platformIdentity is the raw
 * platform-asserted identity (see app/chatgpt-auth.ts's getChatGPTUser),
 * trusted the same way getCurrentUser() trusts it, just before an app_users
 * row exists to join against. The invitation's email must match the
 * platform-asserted email: defense in depth if a claim token ever leaked to
 * the wrong inbox. The audit trail attributes USER_PROVISIONED to the newly
 * created user itself (a genuine self-service claim), not the inviting
 * admin, who is already attributed on the earlier USER_INVITED event.
 */
export async function claimInvitation(
  platformIdentity: PlatformIdentity,
  input: unknown,
  correlationId: string,
): Promise<InvitationClaimResult> {
  const { token } = normalizeInvitationClaim(input);
  const db = await ensureDatabase();
  const invitation = await db.prepare("SELECT id,organisation_id,email,role_code,status,expires_at,invited_by FROM user_invitations WHERE claim_token=?")
    .bind(token).first<{ id: string; organisation_id: string; email: string; role_code: string; status: string; expires_at: string; invited_by: string }>();
  if (!invitation) throw new IdentityValidationError([{ code: "INVITATION_NOT_FOUND", path: "/token", message: "The invitation token is invalid." }]);
  if (invitation.status !== "PENDING") throw new IdentityValidationError([{ code: "INVITATION_NOT_PENDING", path: "/token", message: `This invitation is already ${invitation.status}.` }]);
  if (new Date(invitation.expires_at).getTime() < Date.now()) {
    await db.prepare("UPDATE user_invitations SET status='EXPIRED' WHERE id=?").bind(invitation.id).run();
    throw new IdentityValidationError([{ code: "INVITATION_EXPIRED", path: "/token", message: "This invitation has expired." }]);
  }
  if (invitation.email.toLowerCase() !== platformIdentity.email.toLowerCase()) {
    throw new IdentityValidationError([{ code: "EMAIL_MISMATCH", path: "/token", message: "The authenticated email does not match the invited email." }]);
  }

  const provider = await db.prepare("SELECT id FROM identity_providers WHERE provider_key='SITES_WORKSPACE' AND status='ACTIVE' AND configuration_status='CONFIGURED'")
    .first<{ id: string }>();
  if (!provider) throw new IdentityValidationError([{ code: "PROVIDER_NOT_CONFIGURED", path: "/", message: "The platform identity provider is not configured." }]);
  const existingLink = await db.prepare("SELECT id FROM identity_links WHERE provider_id=? AND subject=?").bind(provider.id, platformIdentity.subject).first<{ id: string }>();
  if (existingLink) throw new RepositoryConflictError("This platform identity is already linked to an account.");

  const organisation = await db.prepare("SELECT id,taxpayer_id FROM organisations WHERE id=?").bind(invitation.organisation_id).first<{ id: string; taxpayer_id: string }>();
  if (!organisation) throw new IdentityValidationError([{ code: "ORGANISATION_NOT_FOUND", path: "/", message: "The inviting organisation no longer exists." }]);

  const now = new Date().toISOString();
  const userId = crypto.randomUUID();
  const identityLinkId = crypto.randomUUID();
  const membershipId = crypto.randomUUID();
  const claimant: UserContext = {
    userId, email: platformIdentity.email, displayName: platformIdentity.displayName, role: invitation.role_code,
    taxpayerId: organisation.taxpayer_id, organisationId: invitation.organisation_id,
    capabilities: [], dynamicPermissions: [], isDevelopmentIdentity: false,
  };
  await db.batch([
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind(userId, platformIdentity.subject, platformIdentity.email, platformIdentity.displayName, invitation.role_code, organisation.taxpayer_id, "ACTIVE", now),
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(identityLinkId, userId, provider.id, platformIdentity.subject, platformIdentity.email, "PLATFORM_AUTHENTICATED", "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_memberships (id,organisation_id,user_id,role_code,branch_id,status,valid_from,valid_to,assigned_by,created_at)
      VALUES (?,?,?,?,NULL,?,?,NULL,?,?)`).bind(membershipId, invitation.organisation_id, userId, invitation.role_code, "ACTIVE", now, invitation.invited_by, now),
    db.prepare("UPDATE user_invitations SET status='CLAIMED',claimed_at=?,claimed_by_user_id=? WHERE id=?").bind(now, userId, invitation.id),
    outboxEvent(db, "IDENTITY", userId, "UserProvisioned", organisation.taxpayer_id, { userId, organisationId: invitation.organisation_id, invitationId: invitation.id, correlationId }),
    await appendAudit(db, claimant, "USER_PROVISIONED", "APP_USER", userId, { organisationId: invitation.organisation_id, roleCode: invitation.role_code, invitationId: invitation.id }),
  ]);
  return { userId, organisationId: invitation.organisation_id, roleCode: invitation.role_code, status: "ACTIVE" };
}

const CORRECTABLE_IDENTIFIER_TYPES = new Set(["VAT_NUMBER", "TIN"]);

export type IdentifierCorrectionResult = {
  taxpayerId: string;
  identifierType: string;
  previousIdentifierId: string;
  newIdentifierId: string;
  identifierValue: string;
  version: number;
};

/**
 * Module 1 Taxpayer IdentifierVersion / correction path. Statutory identity
 * records are never overwritten in place (see MODULE_DEVELOPMENT_PLAYBOOK.md's
 * ground rules): correcting a VAT number or TIN supersedes the current
 * taxpayer_identifiers row (status SUPERSEDED, effective_to set) and inserts
 * a new versioned row linked back via previous_version_id, rather than
 * mutating identifier_value in place. Also keeps taxpayers.vat_number/tin in
 * sync, since those denormalized columns are what actually gets read
 * elsewhere (ClassifyTransaction, invoice counterparty resolution,
 * submitRegistrationApplication's duplicate checks) — without this, the
 * correction would be recorded but have no real effect. Scoped to
 * VAT_NUMBER/TIN only: those are the only identifier types this codebase
 * currently issues, and the only ones with a denormalized taxpayers column
 * to keep in sync.
 */
export async function correctTaxpayerIdentifier(
  actor: UserContext,
  taxpayerId: string,
  identifierId: string,
  input: unknown,
  correlationId: string,
): Promise<IdentifierCorrectionResult> {
  const correction = normalizeIdentifierCorrection(input);
  const db = await ensureDatabase();
  const current = await db.prepare("SELECT id,taxpayer_id,identifier_type,identifier_value,country,status,version FROM taxpayer_identifiers WHERE id=? AND taxpayer_id=?")
    .bind(identifierId, taxpayerId)
    .first<{ id: string; taxpayer_id: string; identifier_type: string; identifier_value: string; country: string; status: string; version: number }>();
  if (!current) {
    throw new IdentityValidationError([{ code: "IDENTIFIER_NOT_FOUND", path: "/identifier_id", message: "The identifier does not exist for this taxpayer." }]);
  }
  if (current.status !== "ACTIVE") {
    throw new IdentityValidationError([{ code: "IDENTIFIER_NOT_ACTIVE", path: "/identifier_id", message: `This identifier is currently ${current.status}; correct the current active version instead.` }]);
  }
  if (!CORRECTABLE_IDENTIFIER_TYPES.has(current.identifier_type)) {
    throw new IdentityValidationError([{ code: "IDENTIFIER_TYPE_NOT_CORRECTABLE", path: "/identifier_id", message: `${current.identifier_type} identifiers cannot be corrected via this command.` }]);
  }
  if (current.identifier_value === correction.identifierValue) {
    throw new IdentityValidationError([{ code: "IDENTIFIER_UNCHANGED", path: "/identifier_value", message: "The corrected value is identical to the current value." }]);
  }
  const duplicateIdentifier = await db.prepare("SELECT id FROM taxpayer_identifiers WHERE identifier_type=? AND identifier_value=? AND country=? AND status='ACTIVE'")
    .bind(current.identifier_type, correction.identifierValue, current.country).first<{ id: string }>();
  if (duplicateIdentifier) throw new RepositoryConflictError("Another active taxpayer already holds this identifier value.");
  const taxpayerColumn = current.identifier_type === "VAT_NUMBER" ? "vat_number" : "tin";
  const duplicateTaxpayer = await db.prepare(`SELECT id FROM taxpayers WHERE ${taxpayerColumn}=? AND id<>?`).bind(correction.identifierValue, taxpayerId).first<{ id: string }>();
  if (duplicateTaxpayer) throw new RepositoryConflictError("Another taxpayer already uses this identifier value.");

  const now = new Date().toISOString();
  const newId = crypto.randomUUID();
  await db.batch([
    db.prepare("UPDATE taxpayer_identifiers SET status='SUPERSEDED',effective_to=? WHERE id=?").bind(now, current.id),
    db.prepare(`INSERT INTO taxpayer_identifiers (id,taxpayer_id,identifier_type,identifier_value,country,status,source,verified_at,created_at,version,effective_from,effective_to,previous_version_id)
      VALUES (?,?,?,?,?,?,?,NULL,?,?,?,NULL,?)`)
      .bind(newId, taxpayerId, current.identifier_type, correction.identifierValue, current.country, "ACTIVE", "MANUAL_CORRECTION", now, current.version + 1, now, current.id),
    db.prepare(`UPDATE taxpayers SET ${taxpayerColumn}=? WHERE id=?`).bind(correction.identifierValue, taxpayerId),
    outboxEvent(db, "TAXPAYER", taxpayerId, "TaxpayerIdentifierCorrected", taxpayerId, {
      taxpayerId, identifierType: current.identifier_type, previousIdentifierId: current.id, newIdentifierId: newId, correlationId,
    }),
    await appendAudit(db, actor, "TAXPAYER_IDENTIFIER_CORRECTED", "TAXPAYER_IDENTIFIER", newId, {
      taxpayerId, identifierType: current.identifier_type, previousValue: current.identifier_value, newValue: correction.identifierValue,
      reason: correction.reason, previousIdentifierId: current.id,
    }),
  ]);
  return { taxpayerId, identifierType: current.identifier_type, previousIdentifierId: current.id, newIdentifierId: newId, identifierValue: correction.identifierValue, version: current.version + 1 };
}

export type IdentifierVerificationResult = {
  taxpayerId: string;
  provider: "ITAS";
  status: "VERIFIED" | "AWAITING_PROVIDER_CONTRACT";
  checkedAt: string;
  requestReference: string | null;
};

/**
 * Module 1 Taxpayer VerifyIdentifiers, as a standalone command re-triggerable
 * any time after registration — submitRegistrationApplication already
 * records one AWAITING_PROVIDER_CONTRACT attempt at intake, but there was
 * previously no way to retry once ITAS becomes available without
 * resubmitting an entire new registration. Calls the same ItasIdentityPort
 * registration already calls (lib/integrations/itas.ts); today that always
 * fails closed with ItasIntegrationUnavailableError since ITAS is
 * unconfigured — that failure is caught and reported honestly as
 * AWAITING_PROVIDER_CONTRACT, never silently swallowed or faked into a
 * success.
 */
export async function verifyTaxpayerIdentifiers(
  actor: UserContext,
  taxpayerId: string,
  correlationId: string,
): Promise<IdentifierVerificationResult> {
  const db = await ensureDatabase();
  const taxpayer = await db.prepare("SELECT id,vat_number,tin FROM taxpayers WHERE id=?").bind(taxpayerId).first<{ id: string; vat_number: string; tin: string }>();
  if (!taxpayer) throw new IdentityValidationError([{ code: "TAXPAYER_NOT_FOUND", path: "/taxpayer_id", message: "The taxpayer does not exist." }]);
  requireTaxpayerScope(actor, taxpayer.id);

  const now = new Date().toISOString();
  const itas = getItasIdentityPort(db);
  try {
    const result = await itas.verifyTaxpayer({ vatNumber: taxpayer.vat_number, tin: taxpayer.tin, correlationId });
    await db.batch([
      db.prepare("UPDATE taxpayer_identifiers SET verified_at=? WHERE taxpayer_id=? AND identifier_type IN ('VAT_NUMBER','TIN') AND status='ACTIVE'").bind(result.checkedAt, taxpayerId),
      // Module 10 Phase B: renamed from the non-conforming "TaxpayerIdentifiersVerified" to the
      // catalog's own "TaxpayerVerified" (event-catalog.csv), payload now carries every minimum field
      // the catalog names (taxpayer_id,source,source_version,verified_at) instead of a convenience shape.
      outboxEvent(db, "TAXPAYER", taxpayerId, "TaxpayerVerified", taxpayerId, { taxpayerId, source: "ITAS", sourceVersion: ITAS_CONTRACT_VERSION, verifiedAt: result.checkedAt, requestReference: result.requestReference, correlationId }),
      await appendAudit(db, actor, "TAXPAYER_IDENTIFIERS_VERIFIED", "TAXPAYER", taxpayerId, { provider: "ITAS", requestReference: result.requestReference }),
    ]);
    return { taxpayerId, provider: "ITAS", status: "VERIFIED", checkedAt: result.checkedAt, requestReference: result.requestReference };
  } catch (error) {
    if (!(error instanceof ItasIntegrationUnavailableError)) throw error;
    const auditStatement = await appendAudit(db, actor, "TAXPAYER_IDENTIFIER_VERIFICATION_ATTEMPTED", "TAXPAYER", taxpayerId, { provider: "ITAS", outcome: "AWAITING_PROVIDER_CONTRACT" });
    await auditStatement.run();
    return { taxpayerId, provider: "ITAS", status: "AWAITING_PROVIDER_CONTRACT", checkedAt: now, requestReference: null };
  }
}

type TargetUserRow = { id: string; status: string; taxpayer_id: string | null };

async function requireUserInScope(db: D1Database, actor: UserContext, userId: string): Promise<TargetUserRow> {
  const targetUser = await db.prepare("SELECT id,status,taxpayer_id FROM app_users WHERE id=?").bind(userId).first<TargetUserRow>();
  if (!targetUser) throw new IdentityValidationError([{ code: "USER_NOT_FOUND", path: "/user_id", message: "The user does not exist." }]);
  if (!isNationalScope(actor) && actor.taxpayerId !== targetUser.taxpayer_id) {
    throw new AccessDeniedError("The requested user is outside your authorised taxpayer scope.");
  }
  return targetUser;
}

export type UserSuspensionResult = { userId: string; status: string };

/**
 * Module 1 Identity SuspendUser: standalone, reversible account lockout —
 * see normalizeUserSuspension in lib/domain/identity.ts for how this differs
 * from terminateEmployee's one-way offboarding. Has a real, immediate
 * effect: getCurrentUser()'s join requires app_users.status='ACTIVE', so a
 * suspended user is rejected on their very next request. Self-suspension is
 * denied for the same lockout reason revokeIdentityLink is admin-only.
 */
export async function suspendUser(
  actor: UserContext,
  userId: string,
  input: unknown,
  correlationId: string,
): Promise<UserSuspensionResult> {
  const { reason } = normalizeUserSuspension(input);
  if (actor.userId === userId) {
    throw new IdentityValidationError([{ code: "SELF_SUSPENSION_DENIED", path: "/user_id", message: "You cannot suspend your own account." }]);
  }
  const db = await ensureDatabase();
  const targetUser = await requireUserInScope(db, actor, userId);
  if (targetUser.status === "SUSPENDED") return { userId, status: "SUSPENDED" };

  await db.batch([
    db.prepare("UPDATE app_users SET status='SUSPENDED' WHERE id=?").bind(userId),
    outboxEvent(db, "IDENTITY", userId, "UserSuspended", targetUser.taxpayer_id ?? userId, { userId, reason, correlationId }),
    await appendAudit(db, actor, "USER_SUSPENDED", "APP_USER", userId, { reason, previousStatus: targetUser.status }),
  ]);
  return { userId, status: "SUSPENDED" };
}

/** Module 1 Identity SuspendUser's reverse: restores a suspended account to ACTIVE. Idempotent. */
export async function reactivateUser(actor: UserContext, userId: string, correlationId: string): Promise<UserSuspensionResult> {
  const db = await ensureDatabase();
  const targetUser = await requireUserInScope(db, actor, userId);
  if (targetUser.status === "ACTIVE") return { userId, status: "ACTIVE" };

  await db.batch([
    db.prepare("UPDATE app_users SET status='ACTIVE' WHERE id=?").bind(userId),
    outboxEvent(db, "IDENTITY", userId, "UserReactivated", targetUser.taxpayer_id ?? userId, { userId, correlationId }),
    await appendAudit(db, actor, "USER_REACTIVATED", "APP_USER", userId, { previousStatus: targetUser.status }),
  ]);
  return { userId, status: "ACTIVE" };
}
