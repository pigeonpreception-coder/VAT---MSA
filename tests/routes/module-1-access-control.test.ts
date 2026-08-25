import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 1 Phase E: route-level, DB-backed negative tests. Everything else
 * in this repo tests pure functions only — db/runtime.ts imports
 * `cloudflare:workers` and app/chatgpt-auth.ts imports `next/headers`,
 * neither of which resolves outside their real runtimes, so no route or
 * repository code could be exercised end-to-end before the fakes under
 * tests/fakes/ and tests/support/fake-d1.ts existed (see those files for
 * what each stands in for and why).
 *
 * NODE_ENV is forced to "production" for this file specifically: db/runtime.ts's
 * ensureDatabase() only seeds its large pilot demo dataset when
 * NODE_ENV !== "production", and that dataset is irrelevant noise for these
 * tests — forcing production also means getCurrentUser() takes its real,
 * header-only authentication path (no local-demo-user fallback), which is
 * more representative of what these negative tests are actually proving.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-owner-a", externalUserId: "ext-owner-a", email: "owner-a@example.test" };
const STAFF_A: FixtureUser = { userId: "usr-staff-a", externalUserId: "ext-staff-a", email: "staff-a@example.test" };
const OWNER_B: FixtureUser = { userId: "usr-owner-b", externalUserId: "ext-owner-b", email: "owner-b@example.test" };
const NAMRA_ADMIN: FixtureUser = { userId: "usr-namra", externalUserId: "ext-namra", email: "namra-admin@example.test" };

function actingAs(user: FixtureUser, extra: Record<string, string> = {}): void {
  __setRequestHeaders({
    "oai-authenticated-user-id": user.externalUserId,
    "oai-authenticated-user-email": user.email,
    ...extra,
  });
}

function jsonRequest(url: string, body: unknown): Request {
  return new Request(url, {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-25T09:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-a", "VAT-A-001", "TIN-A-001", "Org A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 A Street", "finance@org-a.test", now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-b", "VAT-B-001", "TIN-B-001", "Org B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 B Street", "finance@org-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-a", "tp-a", "Org A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-b", "tp-b", "Org B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(STAFF_A.userId, STAFF_A.externalUserId, STAFF_A.email, "Staff A", "TAXPAYER_STAFF", "tp-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_ADMIN.userId, NAMRA_ADMIN.externalUserId, NAMRA_ADMIN.email, "NamRA Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[OWNER_A, STAFF_A, OWNER_B, NAMRA_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 1 route-level access control (Phase E)", () => {
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

  describe("cross-organisation access (ListBranches / branch creation)", () => {
    it("rejects a taxpayer-side owner creating a branch in another organisation", async () => {
      const { POST } = await import("@/app/api/v1/organisations/[id]/branches/route");
      actingAs(OWNER_A);
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/organisations/org-b/branches", { code: "SWK-01", name: "Swakopmund Branch", address: "8 Theo-Ben Gurirab Street" }),
        { params: Promise.resolve({ id: "org-b" }) },
      );
      expect(response.status).toBe(403);
      const body = await response.json();
      expect(body.code).toBe("ACCESS_DENIED");
    });

    it("allows the same owner to create a branch in their own organisation", async () => {
      const { POST } = await import("@/app/api/v1/organisations/[id]/branches/route");
      actingAs(OWNER_A);
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/organisations/org-a/branches", { code: "SWK-01", name: "Swakopmund Branch", address: "8 Theo-Ben Gurirab Street" }),
        { params: Promise.resolve({ id: "org-a" }) },
      );
      expect(response.status).toBe(201);
    });

    it("allows a national-scope NamRA actor to create a branch in any organisation", async () => {
      const { POST } = await import("@/app/api/v1/organisations/[id]/branches/route");
      actingAs(NAMRA_ADMIN);
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/organisations/org-b/branches", { code: "WVB-01", name: "Walvis Bay Branch", address: "44 Sam Nujoma Drive" }),
        { params: Promise.resolve({ id: "org-b" }) },
      );
      expect(response.status).toBe(201);
    });
  });

  describe("cross-role access (insufficient permission)", () => {
    it("rejects a TAXPAYER_STAFF actor creating a branch, even in their own organisation", async () => {
      const { POST } = await import("@/app/api/v1/organisations/[id]/branches/route");
      actingAs(STAFF_A);
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/organisations/org-a/branches", { code: "OTJ-01", name: "Otjiwarongo Branch", address: "1 Hage Geingob Street" }),
        { params: Promise.resolve({ id: "org-a" }) },
      );
      expect(response.status).toBe(403);
      const body = await response.json();
      expect(body.code).toBe("ACCESS_DENIED");
      expect(body.detail).toMatch(/does not have organisations:manage/);
    });
  });

  describe("step-up freshness (taxpayer suspension)", () => {
    it("rejects a suspension with no step-up headers at all", async () => {
      const { POST } = await import("@/app/api/v1/taxpayers/[id]/suspension/route");
      actingAs(NAMRA_ADMIN);
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/taxpayers/tp-a/suspension", { reason: "Repeated non-filing beyond the statutory deadline." }),
        { params: Promise.resolve({ id: "tp-a" }) },
      );
      expect(response.status).toBe(403);
      const body = await response.json();
      expect(body.detail).toMatch(/step-up/i);
    });

    it("rejects a suspension with an expired step-up (older than the 5-minute window)", async () => {
      const { POST } = await import("@/app/api/v1/taxpayers/[id]/suspension/route");
      actingAs(NAMRA_ADMIN);
      const staleTimestamp = new Date(Date.now() - 10 * 60_000).toISOString();
      const request = new Request("https://vat-msa.local/api/v1/taxpayers/tp-a/suspension", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "x-vat-msa-auth-assurance": "MFA_STEP_UP",
          "x-vat-msa-reauthenticated-at": staleTimestamp,
        },
        body: JSON.stringify({ reason: "Repeated non-filing beyond the statutory deadline." }),
      });
      const response = await POST(request, { params: Promise.resolve({ id: "tp-a" }) });
      expect(response.status).toBe(403);
    });

    it("rejects a suspension with a fresh but insufficient assurance level (not MFA_STEP_UP)", async () => {
      const { POST } = await import("@/app/api/v1/taxpayers/[id]/suspension/route");
      actingAs(NAMRA_ADMIN);
      const request = new Request("https://vat-msa.local/api/v1/taxpayers/tp-a/suspension", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "x-vat-msa-auth-assurance": "PASSWORD",
          "x-vat-msa-reauthenticated-at": new Date().toISOString(),
        },
        body: JSON.stringify({ reason: "Repeated non-filing beyond the statutory deadline." }),
      });
      const response = await POST(request, { params: Promise.resolve({ id: "tp-a" }) });
      expect(response.status).toBe(403);
    });

    it("accepts a suspension with a fresh MFA_STEP_UP assurance", async () => {
      const { POST } = await import("@/app/api/v1/taxpayers/[id]/suspension/route");
      actingAs(NAMRA_ADMIN);
      const request = new Request("https://vat-msa.local/api/v1/taxpayers/tp-a/suspension", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "x-vat-msa-auth-assurance": "MFA_STEP_UP",
          "x-vat-msa-reauthenticated-at": new Date().toISOString(),
        },
        body: JSON.stringify({ reason: "Repeated non-filing beyond the statutory deadline." }),
      });
      const response = await POST(request, { params: Promise.resolve({ id: "tp-a" }) });
      expect(response.status).toBe(200);
      const body = await response.json();
      expect(body.suspension).toMatchObject({ taxpayerId: "tp-a", vatStatus: "SUSPENDED" });
    });
  });

  describe("unauthenticated access", () => {
    it("rejects a request carrying no platform identity headers at all", async () => {
      const { POST } = await import("@/app/api/v1/organisations/[id]/branches/route");
      __setRequestHeaders({});
      const response = await POST(
        jsonRequest("https://vat-msa.local/api/v1/organisations/org-a/branches", { code: "GRT-01", name: "Grootfontein Branch", address: "1 Church Street" }),
        { params: Promise.resolve({ id: "org-a" }) },
      );
      expect(response.status).toBe(401);
    });
  });
});
