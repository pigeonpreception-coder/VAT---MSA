import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 3 Phase D: CreateObligation and MarkSatisfied, proven through the
 * real route handlers (app/api/v1/obligations/route.ts and
 * app/api/v1/obligations/[id]/satisfaction/route.ts, both dispatched via
 * lib/api/compliance.ts's handleComplianceCommand). See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-ob-taxpayer-owner", externalUserId: "ext-ob-taxpayer-owner", email: "owner@ob-taxpayer.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-ob-namra", externalUserId: "ext-ob-namra", email: "namra@ob.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ob-taxpayer", "VAT-OB-001", "TIN-OB-001", "Obligation Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Obligation Street", "finance@ob-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ob-taxpayer", "tp-ob-taxpayer", "Obligation Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Taxpayer Owner", "TAXPAYER_OWNER", "tp-ob-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, NAMRA_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 3 compliance obligations (Phase D)", () => {
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

  it("denies a taxpayer-side actor creating an obligation (national-scope only)", async () => {
    const { POST } = await import("@/app/api/v1/obligations/route");
    actingAs(TAXPAYER_OWNER);
    const response = await POST(jsonRequest("https://vat-msa.local/api/v1/obligations", {
      schema_version: "1.0.0", taxpayer_id: "tp-ob-taxpayer", obligation_type: "VAT_RETURN", period_code: "2026-09", due_date: "2026-10-25", amount_cents: 500_000, currency: "NAD",
    }, crypto.randomUUID()));
    expect(response.status).toBe(403);
  });

  it("creates an obligation, is idempotent on retry, and rejects a duplicate under a different key", async () => {
    const { POST } = await import("@/app/api/v1/obligations/route");
    actingAs(NAMRA_OFFICER);
    const payload = { schema_version: "1.0.0", taxpayer_id: "tp-ob-taxpayer", obligation_type: "VAT_RETURN", period_code: "2026-09", due_date: "2026-10-25", amount_cents: 500_000, currency: "NAD" };
    const key = crypto.randomUUID();

    const first = await POST(jsonRequest("https://vat-msa.local/api/v1/obligations", payload, key));
    expect(first.status).toBe(201);
    const firstBody = await first.json();
    expect(firstBody.resource).toMatchObject({ status: "PENDING", obligation_type: "VAT_RETURN", period_code: "2026-09" });

    const retry = await POST(jsonRequest("https://vat-msa.local/api/v1/obligations", payload, key));
    expect(retry.status).toBe(201);
    const retryBody = await retry.json();
    expect(retryBody.resource.id).toBe(firstBody.resource.id);

    const duplicate = await POST(jsonRequest("https://vat-msa.local/api/v1/obligations", payload, crypto.randomUUID()));
    expect(duplicate.status).toBe(409);
  });

  it("marks an obligation satisfied and is idempotent on retry", async () => {
    const { POST: createPOST } = await import("@/app/api/v1/obligations/route");
    actingAs(NAMRA_OFFICER);
    const created = await (await createPOST(jsonRequest("https://vat-msa.local/api/v1/obligations", {
      schema_version: "1.0.0", taxpayer_id: "tp-ob-taxpayer", obligation_type: "VAT_RETURN", period_code: "2026-10", due_date: "2026-11-25", amount_cents: 300_000, currency: "NAD",
    }, crypto.randomUUID()))).json();
    const obligationId = created.resource.id as string;

    const { POST: satisfyPOST } = await import("@/app/api/v1/obligations/[id]/satisfaction/route");
    const satisfyKey = crypto.randomUUID();
    const satisfyPayload = { schema_version: "1.0.0", notes: "Payment confirmed received via bank reconciliation." };
    const first = await satisfyPOST(
      jsonRequest(`https://vat-msa.local/api/v1/obligations/${obligationId}/satisfaction`, satisfyPayload, satisfyKey),
      { params: Promise.resolve({ id: obligationId }) },
    );
    expect(first.status).toBe(200);
    expect((await first.json()).resource.status).toBe("SATISFIED");

    // Same idempotency key + identical payload: returns the prior result.
    const retrySameKey = await satisfyPOST(
      jsonRequest(`https://vat-msa.local/api/v1/obligations/${obligationId}/satisfaction`, satisfyPayload, satisfyKey),
      { params: Promise.resolve({ id: obligationId }) },
    );
    expect(retrySameKey.status).toBe(200);
    expect((await retrySameKey.json()).resource.status).toBe("SATISFIED");

    // A fresh key against an already-satisfied obligation: idempotent no-op, not a re-run.
    const retryNewKey = await satisfyPOST(
      jsonRequest(`https://vat-msa.local/api/v1/obligations/${obligationId}/satisfaction`, { schema_version: "1.0.0", notes: "Confirming idempotency under a different key entirely." }, crypto.randomUUID()),
      { params: Promise.resolve({ id: obligationId }) },
    );
    expect(retryNewKey.status).toBe(200);
    expect((await retryNewKey.json()).resource.status).toBe("SATISFIED");
  });

  it("surfaces the created obligation through GetComplianceCentre", async () => {
    const { GET } = await import("@/app/api/v1/compliance/route");
    actingAs(TAXPAYER_OWNER);
    const response = await GET(new Request("https://vat-msa.local/api/v1/compliance"));
    expect(response.status).toBe(200);
    const body = await response.json();
    const periods = body.obligations.map((obligation: { period_code: string }) => obligation.period_code);
    expect(periods).toEqual(expect.arrayContaining(["2026-09", "2026-10"]));
  });
});
