import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 5 Phase E: the Expense maker-checker workflow and Project budget
 * approval / cost posting / profitability, proven through the real route
 * handlers (app/api/v1/expenses/**, app/api/v1/projects/**, dispatched via
 * lib/api/business.ts) and lib/data/business-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const PREPARER: FixtureUser = { userId: "usr-exp-preparer", externalUserId: "ext-exp-preparer", email: "preparer@exp-test.test" };
const APPROVER: FixtureUser = { userId: "usr-exp-approver", externalUserId: "ext-exp-approver", email: "approver@exp-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey: string): Request {
  return new Request(url, {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": idempotencyKey },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-exp-taxpayer", "VAT-EXP-001", "TIN-EXP-001", "Expense Project Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Expense Street", "finance@exp-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-exp-taxpayer", "tp-exp-taxpayer", "Expense Project Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-exp-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PREPARER.userId, PREPARER.externalUserId, PREPARER.email, "Preparer", "TAXPAYER_ADMIN", "tp-exp-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(APPROVER.userId, APPROVER.externalUserId, APPROVER.email, "Approver", "TAXPAYER_OWNER", "tp-exp-taxpayer", "ACTIVE", now),
    ...[PREPARER, APPROVER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-exp-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function createCategoryRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/expenses/categories/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/expenses/categories", { schema_version: "1.0.0", default_tax_category: "STANDARD", ...body }, key));
}

async function createExpenseRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/expenses/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/expenses", { schema_version: "1.0.0", currency: "NAD", ...body }, key));
}

async function submitExpenseRoute(expenseId: string, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/expenses/[id]/submission/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/expenses/${expenseId}/submission`, { schema_version: "1.0.0" }, key), { params: Promise.resolve({ id: expenseId }) });
}

async function approveExpenseRoute(expenseId: string, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/expenses/[id]/approval/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/expenses/${expenseId}/approval`, { schema_version: "1.0.0" }, key), { params: Promise.resolve({ id: expenseId }) });
}

async function rejectExpenseRoute(expenseId: string, body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/expenses/[id]/rejection/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/expenses/${expenseId}/rejection`, { schema_version: "1.0.0", ...body }, key), { params: Promise.resolve({ id: expenseId }) });
}

async function getExpenseReportRoute(actor: FixtureUser, from?: string, to?: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/expenses/report/route");
  actingAs(actor);
  const params = new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) });
  const query = params.toString();
  return GET(new Request(`https://vat-msa.local/api/v1/expenses/report${query ? `?${query}` : ""}`));
}

async function createProjectRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/projects/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/projects", { schema_version: "1.0.0", currency: "NAD", start_date: "2026-07-01", ...body }, key));
}

async function approveBudgetRoute(projectId: string, body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/projects/[id]/budget-approval/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/projects/${projectId}/budget-approval`, { schema_version: "1.0.0", ...body }, key), { params: Promise.resolve({ id: projectId }) });
}

async function postCostRoute(projectId: string, body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/projects/[id]/costs/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/projects/${projectId}/costs`, { schema_version: "1.0.0", ...body }, key), { params: Promise.resolve({ id: projectId }) });
}

async function getProfitabilityRoute(projectId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/projects/[id]/profitability/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/projects/${projectId}/profitability`), { params: Promise.resolve({ id: projectId }) });
}

describe("Module 5 expense and project workflow (Phase E)", () => {
  let categoryId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();

    const category = await createCategoryRoute({ code: "TRAVEL", name: "Travel" }, PREPARER);
    categoryId = (await category.json()).resource.id;
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("creates an expense category and rejects a duplicate code", async () => {
    const duplicate = await createCategoryRoute({ code: "TRAVEL", name: "Travel again" }, PREPARER);
    expect(duplicate.status).toBe(409);
  });

  it("walks an expense from DRAFT through SUBMITTED to APPROVED, denying self-approval along the way", async () => {
    const created = await createExpenseRoute({
      category_id: categoryId, expense_number: `EXP-${crypto.randomUUID().slice(0, 8)}`, expense_date: "2026-07-10",
      description: "Client site visit travel", net_cents: 1_000, tax_cents: 150, total_cents: 1_150,
    }, PREPARER);
    expect(created.status).toBe(201);
    const expenseId = (await created.json()).resource.id as string;

    const submitted = await submitExpenseRoute(expenseId, PREPARER);
    expect(submitted.status).toBe(200);
    expect((await submitted.json()).resource.status).toBe("SUBMITTED");

    const selfApprove = await approveExpenseRoute(expenseId, PREPARER);
    expect(selfApprove.status).toBe(403);

    const approved = await approveExpenseRoute(expenseId, APPROVER);
    expect(approved.status).toBe(200);
    const approvedBody = await approved.json();
    expect(approvedBody.resource.status).toBe("APPROVED");
    expect(approvedBody.resource.approved_by).toBe(APPROVER.userId);
  });

  it("rejects a submitted expense with a reason, denying self-rejection", async () => {
    const created = await createExpenseRoute({
      category_id: categoryId, expense_number: `EXP-${crypto.randomUUID().slice(0, 8)}`, expense_date: "2026-07-11",
      description: "Disputed taxi claim", net_cents: 500, tax_cents: 75, total_cents: 575,
    }, PREPARER);
    const expenseId = (await created.json()).resource.id as string;
    await submitExpenseRoute(expenseId, PREPARER);

    const selfReject = await rejectExpenseRoute(expenseId, { reason: "Receipt does not match the claimed amount." }, PREPARER);
    expect(selfReject.status).toBe(403);

    const rejected = await rejectExpenseRoute(expenseId, { reason: "Receipt does not match the claimed amount." }, APPROVER);
    expect(rejected.status).toBe(200);
    const rejectedBody = await rejected.json();
    expect(rejectedBody.resource.status).toBe("REJECTED");
    expect(rejectedBody.resource.rejection_reason).toBe("Receipt does not match the claimed amount.");
  });

  it("rejects submitting an already-submitted expense and approving a still-draft expense", async () => {
    const created = await createExpenseRoute({
      category_id: categoryId, expense_number: `EXP-${crypto.randomUUID().slice(0, 8)}`, expense_date: "2026-07-12",
      description: "State-machine guard test", net_cents: 200, tax_cents: 30, total_cents: 230,
    }, PREPARER);
    const expenseId = (await created.json()).resource.id as string;

    const draftApprove = await approveExpenseRoute(expenseId, APPROVER);
    expect(draftApprove.status).toBe(409);

    await submitExpenseRoute(expenseId, PREPARER);
    const doubleSubmit = await submitExpenseRoute(expenseId, PREPARER);
    expect(doubleSubmit.status).toBe(409);
  });

  it("returns an expense report with totals by status and by category", async () => {
    const response = await getExpenseReportRoute(PREPARER, "2026-07-01", "2026-07-31");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(typeof body.total_cents).toBe("number");
    expect(Array.isArray(body.by_status)).toBe(true);
    expect(Array.isArray(body.by_category)).toBe(true);
    const approvedStatus = body.by_status.find((row: { status: string }) => row.status === "APPROVED");
    expect(approvedStatus.total_cents).toBeGreaterThanOrEqual(1_150);
  });

  it("approves a project's budget at an independently chosen amount, denying self-approval by the project's own manager", async () => {
    const created = await createProjectRoute({ code: `PRJ-${crypto.randomUUID().slice(0, 8)}`, name: "Site rollout", budget_cents: 1_000_000 }, PREPARER);
    const projectId = (await created.json()).resource.id as string;

    const selfApprove = await approveBudgetRoute(projectId, { approved_amount_cents: 900_000 }, PREPARER);
    expect(selfApprove.status).toBe(403);

    const approved = await approveBudgetRoute(projectId, { approved_amount_cents: 900_000, notes: "Approved at a reduced amount pending phase 2 scoping." }, APPROVER);
    expect(approved.status).toBe(200);
    const approvedBody = await approved.json();
    expect(approvedBody.resource.status).toBe("APPROVED");
    expect(approvedBody.resource.approved_amount_cents).toBe(900_000);

    const reapprove = await approveBudgetRoute(projectId, { approved_amount_cents: 800_000 }, APPROVER, crypto.randomUUID());
    expect(reapprove.status).toBe(409);
  });

  it("returns 404 approving a budget on a project with no proposed budget", async () => {
    const created = await createProjectRoute({ code: `PRJ-NOBUDGET-${crypto.randomUUID().slice(0, 8)}`, name: "No budget project" }, PREPARER);
    const projectId = (await created.json()).resource.id as string;
    const response = await approveBudgetRoute(projectId, { approved_amount_cents: 100_000 }, APPROVER);
    expect(response.status).toBe(404);
  });

  it("posts an approved expense as a project cost, deriving amount/currency/date from the expense, and rejects posting it twice", async () => {
    const project = await createProjectRoute({ code: `PRJ-COST-${crypto.randomUUID().slice(0, 8)}`, name: "Cost tracking project" }, PREPARER);
    const projectId = (await project.json()).resource.id as string;

    const expense = await createExpenseRoute({
      category_id: categoryId, project_id: projectId, expense_number: `EXP-${crypto.randomUUID().slice(0, 8)}`, expense_date: "2026-07-15",
      description: "Materials for the rollout", net_cents: 8_000, tax_cents: 1_200, total_cents: 9_200,
    }, PREPARER);
    const expenseId = (await expense.json()).resource.id as string;

    const notApproved = await postCostRoute(projectId, { cost_type: "EXPENSE", source_id: expenseId }, PREPARER);
    expect(notApproved.status).toBe(409);

    await submitExpenseRoute(expenseId, PREPARER);
    await approveExpenseRoute(expenseId, APPROVER);

    const posted = await postCostRoute(projectId, { cost_type: "EXPENSE", source_id: expenseId }, PREPARER);
    expect(posted.status).toBe(201);
    const postedBody = await posted.json();
    expect(postedBody.resource.amount_cents).toBe(9_200);
    expect(postedBody.resource.currency).toBe("NAD");

    const duplicate = await postCostRoute(projectId, { cost_type: "EXPENSE", source_id: expenseId }, PREPARER, crypto.randomUUID());
    expect(duplicate.status).toBe(409);
  });

  it("posts a MANUAL project cost with a caller-supplied amount", async () => {
    const project = await createProjectRoute({ code: `PRJ-MANUAL-${crypto.randomUUID().slice(0, 8)}`, name: "Manual cost project" }, PREPARER);
    const projectId = (await project.json()).resource.id as string;
    const response = await postCostRoute(projectId, {
      cost_type: "MANUAL", source_id: "ext-invoice-9001", amount_cents: 42_000, currency: "NAD",
      description: "External contractor invoice not yet in the system.", occurred_at: "2026-07-20",
    }, PREPARER);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.amount_cents).toBe(42_000);
  });

  it("returns a profitability report combining budget, cost and revenue", async () => {
    const project = await createProjectRoute({ code: `PRJ-PROFIT-${crypto.randomUUID().slice(0, 8)}`, name: "Profitability project", budget_cents: 500_000 }, PREPARER);
    const projectId = (await project.json()).resource.id as string;
    await postCostRoute(projectId, {
      cost_type: "MANUAL", source_id: "ext-invoice-9002", amount_cents: 15_000, currency: "NAD",
      description: "Small external cost.", occurred_at: "2026-07-21",
    }, PREPARER);

    const response = await getProfitabilityRoute(projectId, PREPARER);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.cost_cents).toBe(15_000);
    expect(body.revenue_cents).toBe(0);
    expect(body.profit_cents).toBe(-15_000);
    expect(body.budget.amount_cents).toBe(500_000);
  });
});
