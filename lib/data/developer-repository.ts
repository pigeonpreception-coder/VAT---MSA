import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { conformanceOutcome, evaluateClientConformance, TEST_SUITE_VERSION, validateClientCreation, validateCredentialRevocation } from "@/lib/domain/developer";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };

export class DeveloperResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "DeveloperResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new DeveloperResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different developer platform command.");
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

type OrgScope = { organisation_id: string; taxpayer_id: string };

/** api_clients.organisation_id is NOT NULL — this platform's own pre-existing schema, unlike Phase A/C's platform-wide-capable entities. An actor with no taxpayer/organisation at all (a national or platform-technical role) genuinely cannot create an API client in this phase; that is a real, honest limitation of the existing schema, not a gap this phase introduces. */
async function resolveOrganisation(db: D1Database, actor: UserContext): Promise<OrgScope> {
  if (!actor.taxpayerId) throw new AccessDeniedError("An API client must belong to an organisation; national/platform-scope actors have none to create one under.");
  const row = await db.prepare("SELECT id AS organisation_id,taxpayer_id FROM organisations WHERE taxpayer_id=? AND status='ACTIVE'").bind(actor.taxpayerId).first<OrgScope>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active organisation.");
  return row;
}

async function getOrCreateDeveloperAccount(db: D1Database, actor: UserContext, organisationId: string, now: string): Promise<string> {
  const existing = await db.prepare("SELECT id FROM developer_accounts WHERE organisation_id=? AND owner_user_id=?").bind(organisationId, actor.userId).first<{ id: string }>();
  if (existing) return existing.id;
  const id = crypto.randomUUID();
  await db.prepare("INSERT INTO developer_accounts (id,organisation_id,owner_user_id,display_name,status,created_at) VALUES (?,?,?,?,?,?)")
    .bind(id, organisationId, actor.userId, `${actor.displayName}'s developer account`, "ACTIVE", now).run();
  return id;
}

function slugify(name: string): string {
  const slug = name.toLowerCase().replaceAll(/[^a-z0-9]+/g, "_").replaceAll(/^_+|_+$/g, "").slice(0, 40);
  return slug || "client";
}

/**
 * Module 10 Phase D: CreateClient. No separate "create account" verb is
 * named — a DeveloperAccount is get-or-created (one per organisation+actor
 * pair) the first time that actor creates a client, the same "fold the
 * sub-entity into the parent verb" pattern Module 10 Phase C already
 * applied to SaaSProvider/Application. The credential itself stays honest:
 * a client_key (a real, generatable, non-secret identifier — genuinely
 * implementable with no external system) is issued immediately, but
 * status stays PENDING_CREDENTIAL_PROVISIONING, matching this column's own
 * pre-existing seeded value — there is no real secret manager integrated
 * in this environment to mint a live production credential, and this
 * command does not pretend otherwise. credential_reference is always a
 * pointer string (secret-manager://pending/<id>), never a secret value.
 */
export async function createClient(payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateClientCreation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(db, actor);

  const hash = await sha256Hex(stableStringify(input));
  const prior = await replay(db, actor.userId, "CREATE_CLIENT", key, hash);
  if (prior) return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  const developerAccountId = await getOrCreateDeveloperAccount(db, actor, organisation.organisation_id, now);

  const clientId = crypto.randomUUID();
  const credentialId = crypto.randomUUID();
  const clientKey = `${slugify(input.name)}_${clientId.slice(0, 8)}`;
  const credentialReference = `secret-manager://pending/${clientId}`;

  await db.batch([
    db.prepare(`INSERT INTO api_clients (id,organisation_id,developer_account_id,name,client_key,scopes,credential_reference,status,rate_limit_profile,last_rotated_at,expires_at,created_by,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,NULL,NULL,?,?)`).bind(clientId, organisation.organisation_id, developerAccountId, input.name, clientKey, JSON.stringify(input.scopes), credentialReference, "PENDING_CREDENTIAL_PROVISIONING", input.rate_limit_profile, actor.userId, now),
    db.prepare("INSERT INTO credential_refs (id,api_client_id,credential_reference,status,issued_by,issued_at) VALUES (?,?,?,?,?,?)")
      .bind(credentialId, clientId, credentialReference, "ACTIVE", actor.userId, now),
    commandRecord(db, actor.userId, "CREATE_CLIENT", key, hash, "API_CLIENT", clientId, now),
    outbox(db, "API_CLIENT", clientId, "ApiClientCreated", organisation.organisation_id, { apiClientId: clientId, developerAccountId, scopes: input.scopes, correlationId }, now),
    await auditRecord(db, actor, "API_CLIENT_CREATED", "API_CLIENT", clientId, { developerAccountId, scopes: input.scopes, rateLimitProfile: input.rate_limit_profile, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(clientId).first<Record<string, unknown>>();
}

type ClientRow = { id: string; organisation_id: string; status: string; developer_account_id: string | null };

/** Ownership is organisation-wide (any actor belonging to the client's own organisation, or a national-scope actor) — a team resource, not a single individual's, unlike Phase C's SaaSProvider registrant-only model. Mirrors Module 10 Phase A's own loadConnectionForActor org-boundary check. */
async function loadClientForActor(db: D1Database, actor: UserContext, clientId: string): Promise<ClientRow> {
  const row = await db.prepare("SELECT id,organisation_id,status,developer_account_id FROM api_clients WHERE id=?").bind(clientId).first<ClientRow>();
  if (!row) throw new DeveloperResourceError("API client was not found.", 404);
  if (!isNationalScope(actor)) {
    const org = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=? AND id=?").bind(actor.taxpayerId ?? "__none__", row.organisation_id).first<{ id: string }>();
    if (!org) throw new AccessDeniedError("This API client is outside your authorised organisation scope.");
  }
  return row;
}

/** RotateCredential: the currently-ACTIVE credential_refs row is marked ROTATED, a fresh one is issued ACTIVE, and api_clients.credential_reference/last_rotated_at are updated to match — status stays whatever it already was (still PENDING_CREDENTIAL_PROVISIONING in this environment; rotation is meaningful bookkeeping ahead of a real secret manager, not a live re-issuance). */
export async function rotateCredential(clientId: string, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const db = await ensureDatabase();
  const client = await loadClientForActor(db, actor, clientId);
  if (client.status === "REVOKED") throw new RepositoryConflictError("A revoked API client's credential cannot be rotated.");

  const hash = await sha256Hex(stableStringify({ clientId, action: "ROTATE" }));
  const prior = await replay(db, actor.userId, "ROTATE_CREDENTIAL", key, hash);
  if (prior) return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const current = await db.prepare("SELECT id FROM credential_refs WHERE api_client_id=? AND status='ACTIVE'").bind(clientId).first<{ id: string }>();
  const newCredentialId = crypto.randomUUID();
  const newReference = `secret-manager://pending/${clientId}/${newCredentialId.slice(0, 8)}`;
  const now = new Date().toISOString();

  const statements = [
    ...(current ? [db.prepare("UPDATE credential_refs SET status='ROTATED' WHERE id=?").bind(current.id)] : []),
    db.prepare("INSERT INTO credential_refs (id,api_client_id,credential_reference,status,issued_by,issued_at) VALUES (?,?,?,?,?,?)").bind(newCredentialId, clientId, newReference, "ACTIVE", actor.userId, now),
    db.prepare("UPDATE api_clients SET credential_reference=?, last_rotated_at=? WHERE id=?").bind(newReference, now, clientId),
    commandRecord(db, actor.userId, "ROTATE_CREDENTIAL", key, hash, "API_CLIENT", clientId, now),
    outbox(db, "API_CLIENT", clientId, "ApiClientCredentialRotated", client.organisation_id, { apiClientId: clientId, correlationId }, now),
    await auditRecord(db, actor, "API_CLIENT_CREDENTIAL_ROTATED", "API_CLIENT", clientId, { correlationId }, now),
  ];
  await db.batch(statements);
  return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(clientId).first<Record<string, unknown>>();
}

/** RevokeCredential: terminal — marks the current ACTIVE credential_refs row REVOKED and the client itself REVOKED. No un-revoke verb is named by the playbook, so none exists. */
export async function revokeCredential(clientId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const input = validateCredentialRevocation(payload);
  const db = await ensureDatabase();
  const client = await loadClientForActor(db, actor, clientId);
  if (client.status === "REVOKED") throw new RepositoryConflictError("This API client's credential has already been revoked.");

  const hash = await sha256Hex(stableStringify({ clientId, input }));
  const prior = await replay(db, actor.userId, "REVOKE_CREDENTIAL", key, hash);
  if (prior) return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE credential_refs SET status='REVOKED', revoked_by=?, revoked_at=?, revocation_reason=? WHERE api_client_id=? AND status='ACTIVE'").bind(actor.userId, now, input.reason, clientId),
    db.prepare("UPDATE api_clients SET status='REVOKED' WHERE id=?").bind(clientId),
    commandRecord(db, actor.userId, "REVOKE_CREDENTIAL", key, hash, "API_CLIENT", clientId, now),
    outbox(db, "API_CLIENT", clientId, "ApiClientCredentialRevoked", client.organisation_id, { apiClientId: clientId, reason: input.reason, correlationId }, now),
    await auditRecord(db, actor, "API_CLIENT_CREDENTIAL_REVOKED", "API_CLIENT", clientId, { reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM api_clients WHERE id=?").bind(clientId).first<Record<string, unknown>>();
}

/** RunConformance: evaluates lib/domain/developer.ts's fixed check harness against this client's current, real state and persists the result as a TestRun. */
export async function runConformance(clientId: string, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  const db = await ensureDatabase();
  const client = await loadClientForActor(db, actor, clientId);
  const full = await db.prepare("SELECT scopes,rate_limit_profile,status FROM api_clients WHERE id=?").bind(clientId).first<{ scopes: string; rate_limit_profile: string; status: string }>();
  if (!full) throw new DeveloperResourceError("API client was not found.", 404);

  const hash = await sha256Hex(stableStringify({ clientId, action: "RUN_CONFORMANCE" }));
  const prior = await replay(db, actor.userId, "RUN_CONFORMANCE", key, hash);
  if (prior) return db.prepare("SELECT * FROM test_runs WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const currentCredential = await db.prepare("SELECT status FROM credential_refs WHERE api_client_id=? ORDER BY issued_at DESC LIMIT 1").bind(clientId).first<{ status: string }>();
  const checks = evaluateClientConformance({
    scopes: JSON.parse(full.scopes) as string[],
    rateLimitProfile: full.rate_limit_profile,
    clientStatus: full.status,
    currentCredentialStatus: currentCredential?.status ?? null,
  });
  const outcome = conformanceOutcome(checks);

  const runId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO test_runs (id,api_client_id,checks,outcome,run_by,run_at) VALUES (?,?,?,?,?,?)")
      .bind(runId, clientId, JSON.stringify(checks), outcome, actor.userId, now),
    commandRecord(db, actor.userId, "RUN_CONFORMANCE", key, hash, "TEST_RUN", runId, now),
    outbox(db, "API_CLIENT", clientId, outcome === "PASSED" ? "ApiClientConformancePassed" : "ApiClientConformanceFailed", client.organisation_id, { apiClientId: clientId, testRunId: runId, outcome, testSuiteVersion: TEST_SUITE_VERSION, correlationId }, now),
    await auditRecord(db, actor, "API_CLIENT_CONFORMANCE_RUN", "API_CLIENT", clientId, { testRunId: runId, outcome, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM test_runs WHERE id=?").bind(runId).first<Record<string, unknown>>();
}
