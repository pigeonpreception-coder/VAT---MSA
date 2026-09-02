import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { conformanceOutcome, CONFORMANCE_SUITE_VERSION, evaluateConformance, validateConformanceSubmission, validateProviderRegistration } from "@/lib/domain/saas";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };

export class SaasResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "SaasResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new SaasResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different SaaS command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

/** Delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function auditRecord(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

/**
 * Module 10 Phase C: RegisterProvider. No separate "create application" verb
 * exists in the playbook's own list (RegisterProvider, SubmitConformance,
 * GetUsage) — a provider registers with exactly one named application in
 * this same call, creating both the SaaSProvider and Application rows
 * atomically. provider_key reuses the exact same vocabulary Module 10
 * Phase A's integration_connections.provider_key already uses (and Phase B's
 * ITAS row), so a provider vetted here shares its identity with whatever a
 * tenant later registers via RegisterIntegration — though nothing in this
 * phase gates one against the other; that governance link is explicitly
 * out of scope (see the module's own "Required closure" note in the
 * matrix).
 */
export async function registerProvider(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateProviderRegistration(payload);
  const db = await ensureDatabase();

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "REGISTER_PROVIDER", key, hash);
  if (prior) return db.prepare("SELECT * FROM saas_providers WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const existing = await db.prepare("SELECT id FROM saas_providers WHERE provider_key=?").bind(input.provider_key).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError(`A SaaS provider for ${input.provider_key} already exists as ${existing.id}.`);

  const providerId = crypto.randomUUID();
  const applicationId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO saas_providers (id,provider_key,legal_name,contact_email,category,status,registered_by,registered_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind(providerId, input.provider_key, input.legal_name, input.contact_email, input.category, "ACTIVE", actor.userId, now),
    db.prepare(`INSERT INTO saas_applications (id,saas_provider_id,name,description,requested_capabilities,endpoint_reference,status,created_by,created_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(applicationId, providerId, input.application.name, input.application.description, JSON.stringify(input.application.requested_capabilities), input.application.endpoint_reference, "REGISTERED", actor.userId, now),
    commandRecord(db, actor.userId, "REGISTER_PROVIDER", key, hash, "SAAS_PROVIDER", providerId, now),
    outbox(db, "SAAS_PROVIDER", providerId, "SaasProviderRegistered", providerId, { providerKey: input.provider_key, applicationId, correlationId }, now),
    await auditRecord(db, actor, "SAAS_PROVIDER_REGISTERED", "SAAS_PROVIDER", providerId, { providerKey: input.provider_key, applicationId, correlationId }, now),
  ]);
  const provider = await db.prepare("SELECT * FROM saas_providers WHERE id=?").bind(providerId).first<Record<string, unknown>>();
  const application = await db.prepare("SELECT * FROM saas_applications WHERE id=?").bind(applicationId).first<Record<string, unknown>>();
  return { ...provider, application };
}

type ApplicationRow = { id: string; saas_provider_id: string; requested_capabilities: string; registered_by: string; provider_key: string };

async function loadApplicationForActor(db: D1Database, actor: UserContext, applicationId: string): Promise<ApplicationRow> {
  const row = await db.prepare(`SELECT a.id,a.saas_provider_id,a.requested_capabilities,p.registered_by,p.provider_key
    FROM saas_applications a JOIN saas_providers p ON p.id=a.saas_provider_id WHERE a.id=?`).bind(applicationId).first<ApplicationRow>();
  if (!row) throw new SaasResourceError("SaaS application was not found.", 404);
  if (row.registered_by !== actor.userId && !isNationalScope(actor)) throw new AccessDeniedError("Only the registering actor or a national-scope actor may act on this SaaS application.");
  return row;
}

/**
 * SubmitConformance. Runs the fixed, code-versioned conformance harness
 * (lib/domain/saas.ts's evaluateConformance) and records both the run and
 * its consequence for that environment's EnvironmentApproval — GRANTED for
 * a PASSED SANDBOX run (immediately usable — the low-stakes, self-service
 * environment), DENIED for a FAILED run of either environment, and, for a
 * PASSED PRODUCTION run, AWAITING_AUTHORITY rather than GRANTED: production
 * onboarding is a governance decision this phase deliberately does not
 * build a path to grant automatically from a self-submitted, self-run
 * conformance suite alone — the same "fail closed on an unconfirmed
 * authority" posture ITAS/Payment/HSM already apply elsewhere in this
 * codebase, extended here to the one place a purely automated pass could
 * otherwise become a de facto production access grant.
 */
export async function submitConformance(applicationId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateConformanceSubmission(payload);
  const db = await ensureDatabase();
  const application = await loadApplicationForActor(db, actor, applicationId);

  const hash = await sha256Hex(stableStringify({ applicationId, input }));
  const prior = await replay(db, actor.userId, "SUBMIT_CONFORMANCE", key, hash);
  if (prior) return db.prepare("SELECT * FROM saas_conformance_runs WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const priorSandboxPassed = input.environment === "PRODUCTION"
    ? Boolean(await db.prepare("SELECT id FROM saas_conformance_runs WHERE saas_application_id=? AND environment='SANDBOX' AND outcome='PASSED' LIMIT 1").bind(applicationId).first<{ id: string }>())
    : false;

  const requestedCapabilities = JSON.parse(application.requested_capabilities) as string[];
  const checks = evaluateConformance({ requested_capabilities: requestedCapabilities }, input, priorSandboxPassed);
  const outcome = conformanceOutcome(checks);
  const approvalStatus = outcome === "FAILED" ? "DENIED" : input.environment === "SANDBOX" ? "GRANTED" : "AWAITING_AUTHORITY";

  const runId = crypto.randomUUID();
  const now = new Date().toISOString();
  const existingApproval = await db.prepare("SELECT id FROM saas_environment_approvals WHERE saas_application_id=? AND environment=?").bind(applicationId, input.environment).first<{ id: string }>();
  const approvalStatement = existingApproval
    ? db.prepare("UPDATE saas_environment_approvals SET status=?, conformance_run_id=?, updated_at=? WHERE id=?").bind(approvalStatus, runId, now, existingApproval.id)
    : db.prepare("INSERT INTO saas_environment_approvals (id,saas_application_id,environment,status,conformance_run_id,updated_at) VALUES (?,?,?,?,?,?)").bind(crypto.randomUUID(), applicationId, input.environment, approvalStatus, runId, now);

  await db.batch([
    db.prepare(`INSERT INTO saas_conformance_runs (id,saas_application_id,environment,test_suite_version,checks,outcome,submitted_by,submitted_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind(runId, applicationId, input.environment, CONFORMANCE_SUITE_VERSION, JSON.stringify(checks), outcome, actor.userId, now),
    approvalStatement,
    commandRecord(db, actor.userId, "SUBMIT_CONFORMANCE", key, hash, "SAAS_CONFORMANCE_RUN", runId, now),
    outbox(db, "SAAS_APPLICATION", applicationId, outcome === "PASSED" ? "SaasConformancePassed" : "SaasConformanceFailed", application.saas_provider_id, { applicationId, environment: input.environment, outcome, approvalStatus, correlationId }, now),
    await auditRecord(db, actor, "SAAS_CONFORMANCE_SUBMITTED", "SAAS_APPLICATION", applicationId, { environment: input.environment, outcome, approvalStatus, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM saas_conformance_runs WHERE id=?").bind(runId).first<Record<string, unknown>>();
}

/**
 * GetUsage. The only read this phase names, so it doubles as the full
 * provider-detail view: the provider row, every registered application
 * with its conformance/environment-approval standing, and — tying back
 * into Module 10 Phase A's own generic connector model — real usage: every
 * integration_connections row (across every tenant, plus any platform-wide
 * one) sharing this provider's provider_key, and their aggregate sync_jobs
 * history. A provider vetted here and a tenant's own RegisterIntegration
 * call are today two independent, unlinked actions (see registerProvider's
 * own comment); this read is what makes that real-world usage visible
 * despite that.
 */
export async function getUsage(providerId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const provider = await db.prepare("SELECT * FROM saas_providers WHERE id=?").bind(providerId).first<Record<string, unknown> & { registered_by: string; provider_key: string }>();
  if (!provider) throw new SaasResourceError("SaaS provider was not found.", 404);
  if (provider.registered_by !== actor.userId && !isNationalScope(actor)) throw new AccessDeniedError("Only the registering actor or a national-scope actor may view this SaaS provider's usage.");

  const applications = await db.prepare("SELECT * FROM saas_applications WHERE saas_provider_id=? ORDER BY created_at").bind(providerId).all<Record<string, unknown> & { id: string }>();
  const approvals = await db.prepare(`SELECT ea.* FROM saas_environment_approvals ea JOIN saas_applications a ON a.id=ea.saas_application_id WHERE a.saas_provider_id=?`).bind(providerId).all<Record<string, unknown>>();
  const connections = await db.prepare("SELECT id,organisation_id,configuration_status,operational_status FROM integration_connections WHERE provider_key=?").bind(provider.provider_key).all<{ id: string; organisation_id: string | null; configuration_status: string; operational_status: string }>();
  const connectionIds = connections.results.map((connection) => connection.id);
  const syncStats = connectionIds.length === 0
    ? { totalJobs: 0, failedJobs: 0 }
    : await db.prepare(`SELECT COUNT(*) AS totalJobs, SUM(CASE WHEN status='FAILED' THEN 1 ELSE 0 END) AS failedJobs
        FROM sync_jobs WHERE integration_connection_id IN (${connectionIds.map(() => "?").join(",")})`).bind(...connectionIds).first<{ totalJobs: number; failedJobs: number }>() ?? { totalJobs: 0, failedJobs: 0 };

  return {
    provider,
    applications: applications.results,
    environmentApprovals: approvals.results,
    connections: connections.results,
    connectionCount: connections.results.length,
    syncStats,
  };
}
