import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 5 Phase C: the accounting slice — CreateAccount, ReverseJournalEntry,
 * ClosePeriod, TrialBalance and Statements — proven through the real route
 * handlers (app/api/v1/accounting/**, dispatched via lib/api/business.ts)
 * and lib/data/business-repository.ts. This is the first route-level test
 * file for Module 5; see tests/routes/module-1-access-control.test.ts for
 * why this needs the cloudflare:workers/next/headers fakes and the fake D1
 * at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const ACCOUNTANT: FixtureUser = { userId: "usr-acc-accountant", externalUserId: "ext-acc-accountant", email: "accountant@acc-test.test" };
const VIEWER: FixtureUser = { userId: "usr-acc-viewer", externalUserId: "ext-acc-viewer", email: "viewer@acc-test.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-acc-taxpayer", "VAT-ACC-001", "TIN-ACC-001", "Accounting Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Ledger Street", "finance@acc-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-acc-taxpayer", "tp-acc-taxpayer", "Accounting Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-acc-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(ACCOUNTANT.userId, ACCOUNTANT.externalUserId, ACCOUNTANT.email, "Accountant", "TAXPAYER_ACCOUNTANT", "tp-acc-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(VIEWER.userId, VIEWER.externalUserId, VIEWER.email, "Viewer", "TAXPAYER_VIEWER", "tp-acc-taxpayer", "ACTIVE", now),
    ...[ACCOUNTANT, VIEWER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-acc-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function createAccountRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/accounting/accounts/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/accounting/accounts", { schema_version: "1.0.0", currency: "NAD", ...body }, key));
}

async function postJournalRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/accounting/journals/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/accounting/journals", { schema_version: "1.0.0", currency: "NAD", source_type: "MANUAL", ...body }, key));
}

async function reverseJournalRoute(journalId: string, body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/accounting/journals/[id]/reversal/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/accounting/journals/${journalId}/reversal`, { schema_version: "1.0.0", ...body }, key),
    { params: Promise.resolve({ id: journalId }) },
  );
}

async function closePeriodRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/accounting/periods/closure/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/accounting/periods/closure", { schema_version: "1.0.0", ...body }, key));
}

async function getTrialBalanceRoute(actor: FixtureUser, asOf?: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/accounting/trial-balance/route");
  actingAs(actor);
  const url = asOf ? `https://vat-msa.local/api/v1/accounting/trial-balance?as_of=${asOf}` : "https://vat-msa.local/api/v1/accounting/trial-balance";
  return GET(new Request(url));
}

async function getStatementsRoute(actor: FixtureUser, from?: string, to?: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/accounting/statements/route");
  actingAs(actor);
  const params = new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) });
  const query = params.toString();
  return GET(new Request(`https://vat-msa.local/api/v1/accounting/statements${query ? `?${query}` : ""}`));
}

async function getJournalsRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/accounting/journals/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/accounting/journals"));
}

/** Mid-month day in the calendar month before "now", guaranteed fully ended relative to whatever the real system clock reads when tests run. */
function lastMonthDate(): { periodCode: string; journalDate: string } {
  const now = new Date();
  const mid = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - 1, 15));
  const iso = mid.toISOString();
  return { periodCode: iso.slice(0, 7), journalDate: iso.slice(0, 10) };
}

function thisMonthPeriodCode(): string {
  return new Date().toISOString().slice(0, 7);
}

describe("Module 5 accounting (Phase C)", () => {
  let bankAccountId: string;
  let revenueAccountId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();

    const bank = await createAccountRoute({ code: "ACC-1000", name: "Bank", account_type: "ASSET", control_type: "BANK" }, ACCOUNTANT);
    bankAccountId = (await bank.json()).resource.id;
    const revenue = await createAccountRoute({ code: "ACC-4000", name: "Sales revenue", account_type: "REVENUE" }, ACCOUNTANT);
    revenueAccountId = (await revenue.json()).resource.id;
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("creates a chart of accounts entry", async () => {
    const response = await createAccountRoute({ code: "ACC-2000", name: "Accounts payable", account_type: "liability" }, ACCOUNTANT);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource).toMatchObject({ code: "ACC-2000", name: "Accounts payable", account_type: "LIABILITY", status: "ACTIVE" });
  });

  it("rejects a duplicate account code within the same organisation", async () => {
    await createAccountRoute({ code: "ACC-5000", name: "Cost of sales", account_type: "EXPENSE" }, ACCOUNTANT);
    const duplicate = await createAccountRoute({ code: "ACC-5000", name: "Cost of sales again", account_type: "EXPENSE" }, ACCOUNTANT);
    expect(duplicate.status).toBe(409);
  });

  it("denies a read-only actor creating an account", async () => {
    const response = await createAccountRoute({ code: "ACC-9000", name: "Denied account", account_type: "ASSET" }, VIEWER);
    expect(response.status).toBe(403);
  });

  it("posts a balanced journal against real accounts and reflects it in the trial balance", async () => {
    const journalNumber = `JRN-TB-${crypto.randomUUID().slice(0, 8)}`;
    const response = await postJournalRoute({
      journal_number: journalNumber, journal_date: lastMonthDate().journalDate, description: "Cash sale",
      lines: [
        { account_id: bankAccountId, description: "Cash received", debit_cents: 10_000, credit_cents: 0 },
        { account_id: revenueAccountId, description: "Sale recognised", debit_cents: 0, credit_cents: 10_000 },
      ],
    }, ACCOUNTANT);
    expect(response.status).toBe(201);

    const trialBalance = await getTrialBalanceRoute(ACCOUNTANT);
    expect(trialBalance.status).toBe(200);
    const tbBody = await trialBalance.json();
    expect(tbBody.balanced).toBe(true);
    const bankRow = tbBody.accounts.find((row: { account_id: string }) => row.account_id === bankAccountId);
    const revenueRow = tbBody.accounts.find((row: { account_id: string }) => row.account_id === revenueAccountId);
    expect(bankRow.balance_cents).toBeGreaterThanOrEqual(10_000);
    expect(revenueRow.balance_cents).toBeLessThanOrEqual(-10_000);
  });

  it("rejects a journal line referencing an account outside the organisation", async () => {
    const response = await postJournalRoute({
      journal_number: `JRN-BAD-${crypto.randomUUID().slice(0, 8)}`, journal_date: lastMonthDate().journalDate, description: "Invalid reference",
      lines: [
        { account_id: crypto.randomUUID(), description: "Debit", debit_cents: 500, credit_cents: 0 },
        { account_id: bankAccountId, description: "Credit", debit_cents: 0, credit_cents: 500 },
      ],
    }, ACCOUNTANT);
    expect(response.status).toBe(422);
  });

  it("reverses a posted journal: the original flips to REVERSED and the reversal nets its effect to zero", async () => {
    const journalNumber = `JRN-REV-${crypto.randomUUID().slice(0, 8)}`;
    const posted = await postJournalRoute({
      journal_number: journalNumber, journal_date: lastMonthDate().journalDate, description: "Entry to be reversed",
      lines: [
        { account_id: bankAccountId, description: "Debit", debit_cents: 4_242, credit_cents: 0 },
        { account_id: revenueAccountId, description: "Credit", debit_cents: 0, credit_cents: 4_242 },
      ],
    }, ACCOUNTANT);
    const postedBody = await posted.json();
    const originalId = postedBody.resource.id as string;

    const tbBefore = await (await getTrialBalanceRoute(ACCOUNTANT)).json();
    const bankBefore = tbBefore.accounts.find((row: { account_id: string }) => row.account_id === bankAccountId).balance_cents;

    const reversal = await reverseJournalRoute(originalId, { reason: "Posted against the wrong period in error." }, ACCOUNTANT);
    expect(reversal.status).toBe(201);
    const reversalBody = await reversal.json();
    expect(reversalBody.resource.reverses_journal_entry_id).toBe(originalId);

    const journals = await (await getJournalsRoute(ACCOUNTANT)).json();
    const originalRow = journals.journals.find((row: { id: string }) => row.id === originalId);
    expect(originalRow.status).toBe("REVERSED");

    const tbAfter = await (await getTrialBalanceRoute(ACCOUNTANT)).json();
    const bankAfter = tbAfter.accounts.find((row: { account_id: string }) => row.account_id === bankAccountId).balance_cents;
    expect(bankAfter).toBe(bankBefore - 4_242);
  });

  it("rejects reversing an already-reversed journal entry", async () => {
    const journalNumber = `JRN-DOUBLEREV-${crypto.randomUUID().slice(0, 8)}`;
    const posted = await postJournalRoute({
      journal_number: journalNumber, journal_date: lastMonthDate().journalDate, description: "Entry reversed twice",
      lines: [
        { account_id: bankAccountId, description: "Debit", debit_cents: 100, credit_cents: 0 },
        { account_id: revenueAccountId, description: "Credit", debit_cents: 0, credit_cents: 100 },
      ],
    }, ACCOUNTANT);
    const originalId = (await posted.json()).resource.id as string;
    const first = await reverseJournalRoute(originalId, { reason: "First reversal of this test entry." }, ACCOUNTANT);
    expect(first.status).toBe(201);
    const second = await reverseJournalRoute(originalId, { reason: "Second reversal attempt should be rejected." }, ACCOUNTANT);
    expect(second.status).toBe(409);
  });

  it("returns 404 reversing a non-existent journal entry", async () => {
    const response = await reverseJournalRoute(crypto.randomUUID(), { reason: "Reversing something that does not exist." }, ACCOUNTANT);
    expect(response.status).toBe(404);
  });

  it("closes a fully-ended accounting period and blocks further postings into it", async () => {
    const { periodCode, journalDate } = lastMonthDate();
    const closed = await closePeriodRoute({ period_code: periodCode }, ACCOUNTANT);
    expect(closed.status).toBe(200);
    expect((await closed.json()).resource.status).toBe("CLOSED");

    const blocked = await postJournalRoute({
      journal_number: `JRN-CLOSED-${crypto.randomUUID().slice(0, 8)}`, journal_date: journalDate, description: "Should be blocked",
      lines: [
        { account_id: bankAccountId, description: "Debit", debit_cents: 100, credit_cents: 0 },
        { account_id: revenueAccountId, description: "Credit", debit_cents: 0, credit_cents: 100 },
      ],
    }, ACCOUNTANT);
    expect(blocked.status).toBe(409);
  });

  it("is idempotent when closing an already-closed period", async () => {
    const { periodCode } = lastMonthDate();
    const again = await closePeriodRoute({ period_code: periodCode }, ACCOUNTANT);
    expect(again.status).toBe(200);
    expect((await again.json()).resource.status).toBe("CLOSED");
  });

  it("rejects closing a period that has not ended yet", async () => {
    const response = await closePeriodRoute({ period_code: thisMonthPeriodCode() }, ACCOUNTANT);
    expect(response.status).toBe(409);
  });

  it("denies a read-only actor closing a period", async () => {
    const response = await closePeriodRoute({ period_code: lastMonthDate().periodCode }, VIEWER);
    expect(response.status).toBe(403);
  });

  it("returns income statement and balance sheet figures from the statements endpoint", async () => {
    const { journalDate } = lastMonthDate();
    const response = await getStatementsRoute(ACCOUNTANT, journalDate, journalDate);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(typeof body.income_statement.revenue_cents).toBe("number");
    expect(typeof body.income_statement.net_income_cents).toBe("number");
    expect(body.balance_sheet.balanced).toBe(true);
  });

  it("rejects an invalid date range on the statements endpoint", async () => {
    const response = await getStatementsRoute(ACCOUNTANT, "2026-08-31", "2026-08-01");
    expect(response.status).toBe(422);
  });
});
