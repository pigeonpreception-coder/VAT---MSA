import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import {
  authorityGovernanceLocalWritesEnabled,
  normalizeAuthorityOnboardingDecision,
  normalizeAuthorityOnboardingSubmission,
  type AuthorityOnboardingDecisionSubmission,
  type AuthorityOnboardingSubmission,
} from "@/lib/domain/authority-governance";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "@/lib/data/repository";

type IdempotencyRow = { request_hash: string; resource_id: string };
type AuthorityScope = { id: string; jurisdiction_id: string; code: string; name: string; status: string };
type OnboardingCase = {
  id: string;
  tax_authority_id: string;
  authority_name: string;
  target_environment: "LOCAL_STAGING" | "PRODUCTION";
  status: string;
  purpose: string;
  evidence_bundle_hash: string | null;
  readiness_reference: string | null;
  requested_by: string;
  requester_name: string;
  submitted_at: string;
  approved_at: string | null;
  activated_at: string | null;
  updated_at: string;
  decision_type: string | null;
  decision_reason: string | null;
  decided_by_name: string | null;
};

function validateIdempotencyKey(value: string): void {
  if (value.length < 16 || value.length > 128) throw new RepositoryConflictError("Idempotency-Key must contain 16 to 128 characters.");
}

async function priorCommand(db: D1Database, actorId: string, command: string, key: string, requestHash: string): Promise<string | null> {
  const prior = await db.prepare(`SELECT request_hash,resource_id FROM command_idempotency
    WHERE actor_id=? AND command_type=? AND idempotency_key=?`).bind(actorId, command, key).first<IdempotencyRow>();
  if (!prior) return null;
  if (prior.request_hash !== requestHash) throw new RepositoryConflictError("The idempotency key was already used for a different authority-governance command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceId: string, now: string) {
  return db.prepare(`INSERT INTO command_idempotency
    (id,actor_id,command_type,idempotency_key,request_hash,resource_type,resource_id,created_at)
    VALUES (?,?,?,?,?,'TAX_AUTHORITY_ONBOARDING_CASE',?,?)`).bind(crypto.randomUUID(), actorId, command, key, hash, resourceId, now);
}

function outboxRecord(db: D1Database, caseId: string, eventType: string, authorityId: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,'TAX_AUTHORITY_ONBOARDING',?,?,1,?,?,'PENDING',0,?,?,NULL,NULL)`)
    .bind(crypto.randomUUID(), caseId, eventType, authorityId, JSON.stringify(payload), now, now);
}

async function auditRecord(db: D1Database, actor: UserContext, action: string, caseId: string, details: Record<string, unknown>, now: string) {
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = JSON.stringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare(`INSERT INTO audit_events
    (id,actor_id,actor_role,action,resource_type,resource_id,outcome,details,previous_hash,event_hash,occurred_at)
    VALUES (?,?,?,?,'TAX_AUTHORITY_ONBOARDING_CASE',?,'SUCCESS',?,?,?,?)`)
    .bind(id, actor.userId, actor.role, action, caseId, body, prior?.event_hash ?? null, hash, now);
}

async function authorityScope(db: D1Database, actor: UserContext, authorityId: string): Promise<AuthorityScope> {
  const row = await db.prepare(`SELECT ta.id,ta.jurisdiction_id,ta.code,ta.name,ta.status
    FROM tax_authorities ta JOIN tax_authority_administrators admin ON admin.tax_authority_id=ta.id
    WHERE ta.id=? AND admin.user_id=? AND admin.status='ACTIVE'
      AND datetime(admin.effective_from)<=CURRENT_TIMESTAMP
      AND (admin.effective_to IS NULL OR datetime(admin.effective_to)>CURRENT_TIMESTAMP)`)
    .bind(authorityId, actor.userId).first<AuthorityScope>();
  if (!row) throw new AccessDeniedError("The actor is not an active administrator for the requested Tax Authority.");
  return row;
}

async function currentAuthorityReview(db: D1Database, authorityId: string): Promise<void> {
  const review = await db.prepare(`SELECT id FROM tax_authority_access_reviews
    WHERE tax_authority_id=? AND review_type='QUARTERLY' AND status IN ('OPEN','COMPLETED')
      AND date(period_start)<=date('now') AND datetime(due_at)>=CURRENT_TIMESTAMP
    ORDER BY period_start DESC LIMIT 1`).bind(authorityId).first<{ id: string }>();
  if (!review) throw new AccessDeniedError("QUARTERLY_AUTHORITY_ACCESS_REVIEW_REQUIRED: A current Tax Authority access review is required for privileged governance decisions.");
}

async function onboardingCase(db: D1Database, caseId: string, actor: UserContext): Promise<OnboardingCase | null> {
  return db.prepare(`SELECT c.id,c.tax_authority_id,ta.name AS authority_name,c.target_environment,c.status,c.purpose,
    c.evidence_bundle_hash,c.readiness_reference,c.requested_by,requester.display_name AS requester_name,
    c.submitted_at,c.approved_at,c.activated_at,c.updated_at,d.decision_type,d.reason AS decision_reason,
    decider.display_name AS decided_by_name
    FROM tax_authority_onboarding_cases c
    JOIN tax_authorities ta ON ta.id=c.tax_authority_id
    JOIN tax_authority_administrators admin ON admin.tax_authority_id=c.tax_authority_id AND admin.user_id=? AND admin.status='ACTIVE'
    JOIN app_users requester ON requester.id=c.requested_by
    LEFT JOIN tax_authority_onboarding_decisions d ON d.onboarding_case_id=c.id
    LEFT JOIN app_users decider ON decider.id=d.decided_by
    WHERE c.id=? ORDER BY d.occurred_at DESC LIMIT 1`).bind(actor.userId, caseId).first<OnboardingCase>();
}

export async function getAuthorityGovernanceSnapshot(actor: UserContext) {
  const db = await ensureDatabase();
  const authorities = await db.prepare(`SELECT DISTINCT ta.id,ta.jurisdiction_id,ta.code,ta.name,ta.status,tj.name AS jurisdiction_name,
      c.name AS country_name
    FROM tax_authorities ta
    JOIN tax_authority_administrators admin ON admin.tax_authority_id=ta.id AND admin.user_id=? AND admin.status='ACTIVE'
    JOIN tax_jurisdictions tj ON tj.id=ta.jurisdiction_id JOIN countries c ON c.code=tj.country_code
    ORDER BY ta.name`).bind(actor.userId).all<Record<string, string>>();
  const ids = authorities.results.map((item) => item.id);
  if (ids.length === 0) throw new AccessDeniedError("No governed Tax Authority administration scope is assigned to this identity.");
  const placeholders = ids.map(() => "?").join(",");
  const [units, roles, assignments, federation, cases, reviews] = await Promise.all([
    db.prepare(`SELECT id,tax_authority_id,parent_unit_id,code,name,unit_type,status FROM tax_authority_units
      WHERE tax_authority_id IN (${placeholders}) ORDER BY tax_authority_id,parent_unit_id,name`).bind(...ids).all<Record<string, string | null>>(),
    db.prepare("SELECT code,name,duty_class,assurance_required,protected,status FROM tax_authority_role_definitions ORDER BY duty_class,name")
      .all<Record<string, string | number>>(),
    db.prepare(`SELECT a.id,a.tax_authority_id,a.authority_unit_id,a.role_code,r.name AS role_name,r.duty_class,
      u.display_name,u.email,a.scope,a.status,a.effective_from,a.effective_to
      FROM tax_authority_role_assignments a JOIN tax_authority_role_definitions r ON r.code=a.role_code
      JOIN app_users u ON u.id=a.user_id WHERE a.tax_authority_id IN (${placeholders})
      ORDER BY a.tax_authority_id,r.duty_class,u.display_name`).bind(...ids).all<Record<string, string | null>>(),
    db.prepare(`SELECT f.id,f.tax_authority_id,p.provider_key,p.display_name,f.environment,f.protocol,f.status,
      f.assurance_profile,f.checked_at,f.expires_at,f.updated_at
      FROM tax_authority_federation_connections f JOIN identity_providers p ON p.id=f.identity_provider_id
      WHERE f.tax_authority_id IN (${placeholders}) ORDER BY f.tax_authority_id,f.environment`).bind(...ids).all<Record<string, string | null>>(),
    db.prepare(`SELECT c.id,c.tax_authority_id,ta.name AS authority_name,c.target_environment,c.status,c.purpose,
      c.evidence_bundle_hash,c.readiness_reference,c.requested_by,requester.display_name AS requester_name,
      c.submitted_at,c.approved_at,c.activated_at,c.updated_at,d.decision_type,d.reason AS decision_reason,
      decider.display_name AS decided_by_name
      FROM tax_authority_onboarding_cases c JOIN tax_authorities ta ON ta.id=c.tax_authority_id
      JOIN app_users requester ON requester.id=c.requested_by
      LEFT JOIN tax_authority_onboarding_decisions d ON d.onboarding_case_id=c.id
      LEFT JOIN app_users decider ON decider.id=d.decided_by
      WHERE c.tax_authority_id IN (${placeholders}) ORDER BY c.submitted_at DESC`).bind(...ids).all<OnboardingCase>(),
    db.prepare(`SELECT id,tax_authority_id,review_type,period_start,due_at,status,owner_id,completed_by,completed_at
      FROM tax_authority_access_reviews WHERE tax_authority_id IN (${placeholders}) ORDER BY period_start DESC`).bind(...ids).all<Record<string, string | null>>(),
  ]);
  return {
    authorities: authorities.results,
    units: units.results,
    roles: roles.results,
    assignments: assignments.results,
    federation: federation.results,
    onboardingCases: cases.results,
    accessReviews: reviews.results,
    productionActivationEnabled: false,
  };
}

export async function createAuthorityOnboardingCase(
  actor: UserContext,
  payload: AuthorityOnboardingSubmission,
  idempotencyKey: string,
  correlationId: string,
) {
  if (!authorityGovernanceLocalWritesEnabled()) throw new AccessDeniedError("Authority onboarding writes are unavailable in production until the approved operational control plane is deployed.");
  validateIdempotencyKey(idempotencyKey);
  const submission = normalizeAuthorityOnboardingSubmission(payload);
  const db = await ensureDatabase();
  await authorityScope(db, actor, submission.tax_authority_id);
  const requestHash = await sha256Hex(stableStringify(submission));
  const prior = await priorCommand(db, actor.userId, "CREATE_AUTHORITY_ONBOARDING_CASE", idempotencyKey, requestHash);
  if (prior) return onboardingCase(db, prior, actor);
  const duplicate = await db.prepare(`SELECT id FROM tax_authority_onboarding_cases WHERE tax_authority_id=? AND target_environment=?
    AND status IN ('SUBMITTED','UNDER_REVIEW','LOCAL_STAGING_READY','BLOCKED_EXTERNAL') LIMIT 1`)
    .bind(submission.tax_authority_id, submission.target_environment).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`An open ${submission.target_environment} authority-onboarding case already exists as ${duplicate.id}.`);
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const blocked = submission.target_environment === "PRODUCTION";
  const status = blocked ? "BLOCKED_EXTERNAL" : "SUBMITTED";
  const eventType = blocked ? "ProductionAuthorityOnboardingBlocked" : "TaxAuthorityOnboardingRequested";
  const reasonCode = blocked ? "PRODUCTION_AUTHORITY_EVIDENCE_REQUIRED" : "LOCAL_STAGING_REVIEW_REQUIRED";
  const audit = await auditRecord(db, actor, eventType, id, {
    authorityId: submission.tax_authority_id,
    targetEnvironment: submission.target_environment,
    status,
    reasonCode,
    correlationId,
  }, now);
  await db.batch([
    db.prepare(`INSERT INTO tax_authority_onboarding_cases
      (id,tax_authority_id,target_environment,status,purpose,evidence_bundle_hash,readiness_reference,requested_by,
       submitted_at,approved_at,activated_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL,?,?)`).bind(
      id, submission.tax_authority_id, submission.target_environment, status, submission.purpose,
      submission.evidence_bundle_hash ?? null, submission.readiness_reference ?? null, actor.userId, now, now, now,
    ),
    db.prepare(`INSERT INTO tax_authority_governance_events
      (id,tax_authority_id,onboarding_case_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES (?,?,?, ?,NULL,?,?,?,?,?)`).bind(
      crypto.randomUUID(), submission.tax_authority_id, id, eventType, status, reasonCode,
      submission.evidence_bundle_hash ?? null, actor.userId, now,
    ),
    commandRecord(db, actor.userId, "CREATE_AUTHORITY_ONBOARDING_CASE", idempotencyKey, requestHash, id, now),
    outboxRecord(db, id, eventType, submission.tax_authority_id, {
      onboarding_case_id: id,
      tax_authority_id: submission.tax_authority_id,
      target_environment: submission.target_environment,
      status,
      reason_code: reasonCode,
      correlation_id: correlationId,
    }, now),
    audit,
  ]);
  return onboardingCase(db, id, actor);
}

export async function decideAuthorityOnboardingCase(
  actor: UserContext,
  caseId: string,
  payload: AuthorityOnboardingDecisionSubmission,
  idempotencyKey: string,
  correlationId: string,
  stepUpEvidenceReference: string,
) {
  if (!authorityGovernanceLocalWritesEnabled()) throw new AccessDeniedError("Authority onboarding decisions are unavailable in production until the approved operational control plane is deployed.");
  validateIdempotencyKey(idempotencyKey);
  const decision = normalizeAuthorityOnboardingDecision(payload);
  const db = await ensureDatabase();
  const current = await onboardingCase(db, caseId, actor);
  if (!current) throw new AccessDeniedError("The authority-onboarding case is unavailable in the actor's authority scope.");
  await authorityScope(db, actor, current.tax_authority_id);
  await currentAuthorityReview(db, current.tax_authority_id);
  if (current.requested_by === actor.userId) throw new AccessDeniedError("AUTHORITY_ONBOARDING_SELF_APPROVAL_DENIED: The onboarding requester cannot decide the same case.");
  if (!['SUBMITTED', 'UNDER_REVIEW'].includes(current.status)) throw new RepositoryConflictError(`Authority-onboarding case ${caseId} is already ${current.status}.`);
  if (decision.decision === "APPROVE_LOCAL_STAGING" && current.target_environment !== "LOCAL_STAGING") {
    throw new RepositoryConflictError("Production authority onboarding cannot be approved through the local/staging decision command.");
  }
  const requestHash = await sha256Hex(stableStringify({ caseId, decision }));
  const prior = await priorCommand(db, actor.userId, "DECIDE_AUTHORITY_ONBOARDING_CASE", idempotencyKey, requestHash);
  if (prior) return onboardingCase(db, prior, actor);
  const now = new Date().toISOString();
  const nextStatus = decision.decision === "APPROVE_LOCAL_STAGING" ? "LOCAL_STAGING_READY" : "REJECTED";
  const decisionType = decision.decision === "APPROVE_LOCAL_STAGING" ? "LOCAL_STAGING_APPROVAL" : "REJECTION";
  const eventType = decision.decision === "APPROVE_LOCAL_STAGING" ? "TaxAuthorityLocalStagingApproved" : "TaxAuthorityOnboardingRejected";
  const reasonCode = decision.decision === "APPROVE_LOCAL_STAGING" ? "LOCAL_STAGING_ONLY_NO_PRODUCTION_EFFECT" : "AUTHORITY_ONBOARDING_REJECTED";
  const evidenceHash = await sha256Hex(stableStringify({ caseId, decisionType, decision: decision.decision, reason: decision.reason, decidedBy: actor.userId, stepUpEvidenceReference, now }));
  const audit = await auditRecord(db, actor, eventType, caseId, {
    authorityId: current.tax_authority_id,
    fromStatus: current.status,
    toStatus: nextStatus,
    reasonCode,
    correlationId,
  }, now);
  await db.batch([
    db.prepare(`INSERT INTO tax_authority_onboarding_decisions
      (id,onboarding_case_id,decision_type,decision,reason,requested_by,decided_by,evidence_hash,step_up_evidence_reference,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), caseId, decisionType, decision.decision === "APPROVE_LOCAL_STAGING" ? "APPROVE" : "REJECT",
      decision.reason, current.requested_by, actor.userId, evidenceHash, stepUpEvidenceReference, now,
    ),
    db.prepare(`UPDATE tax_authority_onboarding_cases SET status=?,approved_at=?,updated_at=? WHERE id=? AND status IN ('SUBMITTED','UNDER_REVIEW')`)
      .bind(nextStatus, decision.decision === "APPROVE_LOCAL_STAGING" ? now : null, now, caseId),
    db.prepare(`INSERT INTO tax_authority_governance_events
      (id,tax_authority_id,onboarding_case_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), current.tax_authority_id, caseId, eventType, current.status, nextStatus, reasonCode, evidenceHash, actor.userId, now,
    ),
    commandRecord(db, actor.userId, "DECIDE_AUTHORITY_ONBOARDING_CASE", idempotencyKey, requestHash, caseId, now),
    outboxRecord(db, caseId, eventType, current.tax_authority_id, {
      onboarding_case_id: caseId,
      tax_authority_id: current.tax_authority_id,
      target_environment: current.target_environment,
      status: nextStatus,
      reason_code: reasonCode,
      correlation_id: correlationId,
    }, now),
    audit,
  ]);
  return onboardingCase(db, caseId, actor);
}
