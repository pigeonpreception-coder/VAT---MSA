import { ensureDatabase } from "@/db/runtime";
import { isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import {
  IdentityValidationError,
  normalizeAndValidateRegistration,
  normalizeMembershipAssignment,
  normalizeRegistrationDecision,
  type RegistrationSubmission,
} from "@/lib/domain/identity";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { getItasIdentityPort } from "@/lib/integrations/itas";
import { RepositoryConflictError } from "./repository";

async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = stableStringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(id, actor.userId, actor.role, action, resourceType, resourceId, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
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
 * user identity — see MODULE_DEVELOPMENT_PLAYBOOK.md's Module 1 gap notes on
 * self-service provisioning being an open security-policy decision, not an
 * engineering default this command should make unilaterally.
 */
export async function assignMembership(
  actor: UserContext,
  organisationId: string,
  input: unknown,
  correlationId: string,
): Promise<MembershipAssignmentResult> {
  const assignment = normalizeMembershipAssignment(input);
  const db = await ensureDatabase();
  const organisation = await db.prepare("SELECT id,taxpayer_id FROM organisations WHERE id=?").bind(organisationId).first<{ id: string; taxpayer_id: string }>();
  if (!organisation) {
    throw new IdentityValidationError([{ code: "ORGANISATION_NOT_FOUND", path: "/organisation_id", message: "The organisation does not exist." }]);
  }
  requireTaxpayerScope(actor, organisation.taxpayer_id);

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
