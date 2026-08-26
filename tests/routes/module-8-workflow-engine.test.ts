import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { quarterlyAccessReviewWindow } from "@/lib/domain/control-plane";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 8 Phase C: a 2026-08-26 audit found a real, sophisticated
 * multi-table workflow engine already existed (Create/Publish/Decide, real
 * SoD-violation detection on self-approval) but was completely unreachable
 * end-to-end — zero `INSERT INTO workflow_instances`/`workflow_assignments`
 * existed anywhere outside their own CREATE TABLE statements, meaning no
 * fresh database could ever produce a real workflow task. This phase adds
 * Assign (the missing piece), fixes a real defect in Decide that would have
 * silently ignored every node past the first in a multi-approval workflow,
 * fixes a real authorization gap for role-assigned tasks, and adds
 * Test/Delegate. Proven through the real route handlers
 * (app/api/v1/workflows/instances, .../versions/:id/test,
 * .../delegations..., app/api/v1/workflow-tasks/:id/decision, dispatched
 * directly — this module's own convention, no lib/api handler layer) and
 * lib/data/control-plane-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all. This is
 * also the first-ever route-level test file for control-plane-repository.ts
 * as a whole — none of createWorkflowDraft/publishWorkflowVersion/
 * assertEntitledOperation's entitlement plumbing had prior test coverage.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-wf-owner", externalUserId: "ext-wf-owner", email: "owner@wf-test.test" };
const ADMIN: FixtureUser = { userId: "usr-wf-admin", externalUserId: "ext-wf-admin", email: "admin@wf-test.test" };
const ROLE_HOLDER: FixtureUser = { userId: "usr-wf-role-holder", externalUserId: "ext-wf-role-holder", email: "role-holder@wf-test.test" };
const MANAGER_USER: FixtureUser = { userId: "usr-wf-manager", externalUserId: "ext-wf-manager", email: "manager@wf-test.test" };
const DELEGATE_USER: FixtureUser = { userId: "usr-wf-delegate", externalUserId: "ext-wf-delegate", email: "delegate@wf-test.test" };
const ACCOUNTANT: FixtureUser = { userId: "usr-wf-accountant", externalUserId: "ext-wf-accountant", email: "accountant@wf-test.test" };

const ORG_ID = "org-wf-a";
const ROLE_ID = "role-wf-approver";

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function orgUrl(path: string): string {
  return `https://vat-msa.local${path}?organisation_id=${ORG_ID}`;
}

function jsonRequest(url: string, body: unknown, stepUp = true): Request {
  return new Request(url, {
    method: "POST",
    headers: { "content-type": "application/json", ...(stepUp ? { "x-vat-msa-auth-assurance": "MFA_STEP_UP", "x-vat-msa-reauthenticated-at": new Date().toISOString() } : {}) },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  const review = quarterlyAccessReviewWindow();
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-wf-a", "VAT-WF-A", "TIN-WF-A", "Workflow Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Workflow Street", "finance@wf-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind(ORG_ID, "tp-wf-a", "Workflow Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-wf-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-wf-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(ADMIN.userId, ADMIN.externalUserId, ADMIN.email, "Admin", "TAXPAYER_ADMIN", "tp-wf-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(ROLE_HOLDER.userId, ROLE_HOLDER.externalUserId, ROLE_HOLDER.email, "Role Holder", "TAXPAYER_ADMIN", "tp-wf-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(MANAGER_USER.userId, MANAGER_USER.externalUserId, MANAGER_USER.email, "Manager", "TAXPAYER_ADMIN", "tp-wf-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(DELEGATE_USER.userId, DELEGATE_USER.externalUserId, DELEGATE_USER.email, "Delegate", "TAXPAYER_ADMIN", "tp-wf-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(ACCOUNTANT.userId, ACCOUNTANT.externalUserId, ACCOUNTANT.email, "Accountant", "TAXPAYER_ACCOUNTANT", "tp-wf-a", "ACTIVE", now),
    ...[OWNER, ADMIN, ROLE_HOLDER, MANAGER_USER, DELEGATE_USER, ACCOUNTANT].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-wf-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO license_plans (id,code,name,version,status,effective_from,effective_to,created_at)
      VALUES (?,?,?,?,?,?,NULL,?)`).bind("plan-wf-test", "WF_TEST_PLAN", "Workflow Test Plan", 1, "ACTIVE", now, now),
    db.prepare(`INSERT INTO license_features VALUES ('ADVANCED_WORKFLOW','Advanced workflow','Versioned conditional workflow and access governance','WORKFLOWS',1,?)`).bind(now),
    db.prepare(`INSERT INTO license_features VALUES ('USER_SEATS','User seats','Active organisation users','USER_SEATS',0,?)`).bind(now),
    db.prepare(`INSERT INTO license_plan_entitlements VALUES ('ent-wf-test','plan-wf-test','ADVANCED_WORKFLOW',1,NULL,'{}')`),
    db.prepare(`INSERT INTO license_plan_entitlements VALUES ('ent-wf-seats','plan-wf-test','USER_SEATS',1,25,'{}')`),
    db.prepare(`INSERT INTO subscriptions (id,organisation_id,provider,provider_reference,status,activated_at,current_period_start,current_period_end,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("sub-wf-a", ORG_ID, "LOCAL_SYNTHETIC", "synthetic-subscription-wf-a", "ACTIVE", now, "2026-08-01", "2026-10-31", now, now),
    db.prepare(`INSERT INTO organisation_licenses (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
      VALUES (?,?,?,?,?,?,?,NULL,NULL,?,?)`).bind("olic-wf-a", ORG_ID, "sub-wf-a", "plan-wf-test", "ACTIVE", 1, now, "NON_DESTRUCTIVE_TAX_RETENTION", now),
    db.prepare(`INSERT INTO license_usage VALUES ('usage-wf-a','olic-wf-a',?,'WORKFLOWS','2026-Q3',0,0,1,?)`).bind(ORG_ID, now),
    db.prepare(`INSERT INTO access_reviews VALUES (?,?,?,?,?,?,?,?,?,?)`)
      .bind("areview-wf-a", ORG_ID, "Workflow test access review", "QUARTERLY", "COMPLETED", review.periodStart, review.dueAt, OWNER.userId, now, now),
    db.prepare(`INSERT INTO sod_rules (id,organisation_id,code,name,action_set,scope,mandatory,status,effective_from,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("sodrule-wf-a", ORG_ID, "NO_SELF_APPROVAL", "No self approval", '["WORKFLOW_DECISION"]', "ORGANISATION", 1, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_roles (id,organisation_id,name,description,version,branch_scope,approval_limit_cents,status,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind(ROLE_ID, ORG_ID, "Workflow Approver", "Approves high-value expense workflow tasks.", 1, "[]", null, "ACTIVE", OWNER.userId, now, now),
    db.prepare(`INSERT INTO user_role_assignments (id,organisation_id,user_id,employee_id,organisation_role_id,status,effective_from,effective_to,assigned_by,created_at)
      VALUES (?,?,?,NULL,?,?,?,NULL,?,?)`).bind("ura-wf-a", ORG_ID, ROLE_HOLDER.userId, ROLE_ID, "ACTIVE", now, OWNER.userId, now),
    db.prepare(`INSERT INTO employees (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,NULL,NULL,NULL,NULL,NULL,?,?,?,?,NULL,NULL,?,?)`).bind("emp-wf-manager", ORG_ID, MANAGER_USER.userId, "EMP-WF-MGR", "Manager", MANAGER_USER.email, null, "ACTIVE", now, now, now, now),
    db.prepare(`INSERT INTO employees (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,NULL,NULL,NULL,NULL,NULL,?,?,?,?,NULL,NULL,?,?)`).bind("emp-wf-owner", ORG_ID, OWNER.userId, "EMP-WF-OWN", "Owner", OWNER.email, "emp-wf-manager", "ACTIVE", now, now, now, now),
  ]);
}

async function createDraftRoute(actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl("/api/v1/workflows"), body));
}

async function publishRoute(versionId: string, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/versions/[id]/publication/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl(`/api/v1/workflows/versions/${versionId}/publication`), {}), { params: Promise.resolve({ id: versionId }) });
}

async function testRoute(versionId: string, actor: FixtureUser, context: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/versions/[id]/test/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl(`/api/v1/workflows/versions/${versionId}/test`), { context }, false), { params: Promise.resolve({ id: versionId }) });
}

async function assignRoute(actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/instances/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl("/api/v1/workflows/instances"), body));
}

async function decideRoute(assignmentId: string, actor: FixtureUser, decision: string, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflow-tasks/[id]/decision/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl(`/api/v1/workflow-tasks/${assignmentId}/decision`), { decision, reason }), { params: Promise.resolve({ id: assignmentId }) });
}

async function createDelegationRoute(actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/delegations/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl("/api/v1/workflows/delegations"), body));
}

async function listDelegationsRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/workflows/delegations/route");
  actingAs(actor);
  return GET(new Request(orgUrl("/api/v1/workflows/delegations")));
}

async function revokeDelegationRoute(delegationId: string, actor: FixtureUser, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/workflows/delegations/[id]/revocation/route");
  actingAs(actor);
  return POST(jsonRequest(orgUrl(`/api/v1/workflows/delegations/${delegationId}/revocation`), { reason }), { params: Promise.resolve({ id: delegationId }) });
}

const WORKFLOW_DEFINITION = {
  name: "Expense Approval",
  domain_action: "EXPENSE",
  nodes: [
    { id: "start", type: "START", label: "Start" },
    { id: "approve-low", type: "APPROVAL", assignee_type: "USER", assignee_ref: ADMIN.userId, label: "Low value approval" },
    { id: "approve-role", type: "APPROVAL", assignee_type: "ROLE", assignee_ref: ROLE_ID, label: "High value role approval" },
    { id: "approve-manager", type: "APPROVAL", assignee_type: "MANAGER", label: "Manager approval" },
    { id: "end", type: "END", label: "End" },
  ],
  transitions: [
    { from: "start", to: "approve-low", condition: { field: "amount_cents", operator: "LTE", value: 100_000 } },
    { from: "start", to: "approve-role", condition: { field: "amount_cents", operator: "GT", value: 100_000 } },
    { from: "approve-low", to: "end" },
    { from: "approve-role", to: "approve-manager" },
    { from: "approve-manager", to: "end" },
  ],
};

describe("Module 8 workflow engine: Assign/Test/Delegate and Decide's multi-node traversal (Phase C)", () => {
  let versionId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("creates a draft workflow with a conditional multi-node approval graph", async () => {
    const response = await createDraftRoute(OWNER, WORKFLOW_DEFINITION);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.workflow.status).toBe("DRAFT");
    versionId = body.workflow.versionId as string;
  });

  it("dry-runs the routing for both branches via Test, with no side effects", async () => {
    const low = await testRoute(versionId, OWNER, { amount_cents: 50_000 });
    expect(low.status).toBe(200);
    const lowBody = await low.json();
    expect(lowBody.test.path.map((node: { nodeKey: string }) => node.nodeKey)).toEqual(["start", "approve-low", "end"]);
    expect(lowBody.test.terminal).toBe("COMPLETED");

    const high = await testRoute(versionId, OWNER, { amount_cents: 200_000 });
    const highBody = await high.json();
    expect(highBody.test.path.map((node: { nodeKey: string }) => node.nodeKey)).toEqual(["start", "approve-role", "approve-manager", "end"]);
  });

  it("publishes the draft version, activating the workflow (a different actor than the one who drafted it)", async () => {
    const response = await publishRoute(versionId, ADMIN);
    expect(response.status).toBe(200);
    expect((await response.json()).workflowVersion.status).toBe("PUBLISHED");
  });

  it("refuses to assign a workflow for a domain action with no configured, published workflow", async () => {
    const response = await assignRoute(OWNER, { domain_action: "JOURNAL", resource_type: "JOURNAL_ENTRY", resource_id: "jrn-wf-0001" });
    expect(response.status).toBe(422);
  });

  it("assigns a low-value instance straight to its USER-type assignee and completes it once approved", async () => {
    const assigned = await assignRoute(OWNER, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0001", context: { amount_cents: 50_000 } });
    expect(assigned.status).toBe(201);
    const assignedBody = await assigned.json();
    expect(assignedBody.instance.status).toBe("IN_PROGRESS");
    expect(assignedBody.instance.currentNode).toBe("approve-low");
    const assignmentId = assignedBody.instance.assignmentId as string;

    const selfDecision = await decideRoute(assignmentId, OWNER, "APPROVE", "Approving my own submission.");
    expect(selfDecision.status).toBe(422);
    const violation = await env.DB.prepare("SELECT id FROM sod_violations WHERE resource_id=?").bind(assignmentId).first<{ id: string }>();
    expect(violation).toBeTruthy();
    const violationEvent = await env.DB.prepare("SELECT id FROM outbox_events WHERE event_type='SoDViolationDetected' AND aggregate_id=?").bind(assignedBody.instance.id).first<{ id: string }>();
    expect(violationEvent).toBeTruthy();

    const decided = await decideRoute(assignmentId, ADMIN, "APPROVE", "Confirmed the receipt matches.");
    expect(decided.status).toBe(200);
    const decidedBody = await decided.json();
    expect(decidedBody.decision.instanceStatus).toBe("COMPLETED");

    const redecide = await decideRoute(assignmentId, ADMIN, "APPROVE", "Trying to decide it again.");
    expect(redecide.status).toBe(409);
  });

  it("traverses a multi-hop path (ROLE then MANAGER) for a high-value instance, enforcing role-based decide authorization along the way", async () => {
    const assigned = await assignRoute(OWNER, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0002", context: { amount_cents: 200_000 } });
    expect(assigned.status).toBe(201);
    const assignedBody = await assigned.json();
    expect(assignedBody.instance.currentNode).toBe("approve-role");
    const firstAssignmentId = assignedBody.instance.assignmentId as string;

    const wrongDecider = await decideRoute(firstAssignmentId, ADMIN, "APPROVE", "Attempting without holding the role.");
    expect(wrongDecider.status).toBe(422);

    const roleDecision = await decideRoute(firstAssignmentId, ROLE_HOLDER, "APPROVE", "Approved as the designated role holder.");
    expect(roleDecision.status).toBe(200);
    const roleDecisionBody = await roleDecision.json();
    expect(roleDecisionBody.decision.instanceStatus).toBe("IN_PROGRESS");
    const nextAssignmentId = roleDecisionBody.decision.nextAssignmentId as string;
    expect(nextAssignmentId).toBeTruthy();

    const managerRow = await env.DB.prepare("SELECT assigned_user_id,node_key FROM workflow_assignments WHERE id=?").bind(nextAssignmentId).first<{ assigned_user_id: string; node_key: string }>();
    expect(managerRow?.node_key).toBe("approve-manager");
    expect(managerRow?.assigned_user_id).toBe(MANAGER_USER.userId);

    const managerDecision = await decideRoute(nextAssignmentId, MANAGER_USER, "APPROVE", "Approved by the initiator's manager.");
    expect(managerDecision.status).toBe(200);
    expect((await managerDecision.json()).decision.instanceStatus).toBe("COMPLETED");
  });

  it("terminates the whole instance immediately on a single rejection, even mid-chain", async () => {
    const assigned = await assignRoute(OWNER, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0003", context: { amount_cents: 200_000 } });
    const assignmentId = (await assigned.json()).instance.assignmentId as string;

    const rejected = await decideRoute(assignmentId, ROLE_HOLDER, "REJECT", "The supporting documentation is incomplete.");
    expect(rejected.status).toBe(200);
    expect((await rejected.json()).decision.instanceStatus).toBe("REJECTED");
  });

  it("redirects an assignment through an active delegation, then reverts once the delegation is revoked", async () => {
    const future = new Date(Date.now() + 3_600_000).toISOString();
    const past = new Date(Date.now() - 60_000).toISOString();
    const created = await createDelegationRoute(OWNER, { delegator_user_id: ADMIN.userId, delegate_user_id: DELEGATE_USER.userId, effective_from: past, effective_to: future, reason: "Admin is on leave this week." });
    expect(created.status).toBe(201);
    const delegationId = (await created.json()).delegation.id as string;

    const listed = await listDelegationsRoute(OWNER);
    expect((await listed.json()).delegations.some((item: { id: string }) => item.id === delegationId)).toBe(true);

    const assigned = await assignRoute(OWNER, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0004", context: { amount_cents: 50_000 } });
    const assignmentId = (await assigned.json()).instance.assignmentId as string;
    const row = await env.DB.prepare("SELECT assigned_user_id FROM workflow_assignments WHERE id=?").bind(assignmentId).first<{ assigned_user_id: string }>();
    expect(row?.assigned_user_id).toBe(DELEGATE_USER.userId);

    const originalAssigneeDenied = await decideRoute(assignmentId, ADMIN, "APPROVE", "The original assignee tries to decide it anyway.");
    expect(originalAssigneeDenied.status).toBe(422);
    const delegateDecides = await decideRoute(assignmentId, DELEGATE_USER, "APPROVE", "Deciding on Admin's behalf while they are on leave.");
    expect(delegateDecides.status).toBe(200);

    const revoked = await revokeDelegationRoute(delegationId, OWNER, "Admin is back from leave.");
    expect(revoked.status).toBe(200);
    expect((await revoked.json()).delegation.status).toBe("REVOKED");

    const reassigned = await assignRoute(OWNER, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0005", context: { amount_cents: 50_000 } });
    const reassignedAssignmentId = (await reassigned.json()).instance.assignmentId as string;
    const revertedRow = await env.DB.prepare("SELECT assigned_user_id FROM workflow_assignments WHERE id=?").bind(reassignedAssignmentId).first<{ assigned_user_id: string }>();
    expect(revertedRow?.assigned_user_id).toBe(ADMIN.userId);
  });

  it("denies workflow management actions to an actor without workflows:manage", async () => {
    const draft = await createDraftRoute(ACCOUNTANT, WORKFLOW_DEFINITION);
    expect(draft.status).toBe(403);
    const assign = await assignRoute(ACCOUNTANT, { domain_action: "EXPENSE", resource_type: "EXPENSE", resource_id: "exp-wf-0006" });
    expect(assign.status).toBe(403);
    const delegate = await createDelegationRoute(ACCOUNTANT, { delegator_user_id: ADMIN.userId, delegate_user_id: DELEGATE_USER.userId, effective_from: new Date().toISOString(), effective_to: new Date(Date.now() + 3_600_000).toISOString(), reason: "Unauthorised attempt." });
    expect(delegate.status).toBe(403);
  });
});
