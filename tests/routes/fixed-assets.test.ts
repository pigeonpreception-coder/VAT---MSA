import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Operations > Immovable Asset Management and Movable Asset Management
 * (NamRA e-VAT MS master prompt section 16E): RegisterFixedAsset/
 * RecordFixedAssetValuation/FlagMaintenanceFixedAsset/RestoreFixedAsset/
 * DisposeFixedAsset/ListFixedAssets/GetFixedAsset against fixed_assets. One
 * shared lifecycle backs both Operations pages, discriminated by
 * asset_class — this file proves the class/category coupling, the
 * ACTIVE<->UNDER_MAINTENANCE<->DISPOSED state machine, and tenant/national
 * ownership scoping, mirroring tests/routes/taxpayer-systems.test.ts's shape.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-fa-owner-a", externalUserId: "ext-fa-owner-a", email: "owner-a@fa-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-fa-owner-b", externalUserId: "ext-fa-owner-b", email: "owner-b@fa-test.test" };
const PILOT_ADMIN: FixtureUser = { userId: "usr-fa-pilot-admin", externalUserId: "ext-fa-pilot-admin", email: "pilot-admin@fa-test.test" };
const STAFF: FixtureUser = { userId: "usr-fa-staff", externalUserId: "ext-fa-staff", email: "staff@fa-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, method: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method, headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-fa-a", "VAT-FA-A01", "TIN-FA-A01", "Fixed Asset Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Asset Street", "finance@fa-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-fa-a", "tp-fa-a", "Fixed Asset Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-fa-b", "VAT-FA-B01", "TIN-FA-B01", "Fixed Asset Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Asset Street", "finance@fa-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-fa-b", "tp-fa-b", "Fixed Asset Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-fa-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-fa-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-fa-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PILOT_ADMIN.userId, PILOT_ADMIN.externalUserId, PILOT_ADMIN.email, "Pilot Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(STAFF.userId, STAFF.externalUserId, STAFF.email, "Staff", "TAXPAYER_VIEWER", "tp-fa-a", "ACTIVE", now),
    ...[OWNER_A, OWNER_B, PILOT_ADMIN, STAFF].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-fa-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function registerRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/fixed-assets/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/fixed-assets", "POST", body, idempotencyKey));
}

async function valuationRoute(actor: FixtureUser, id: string, currentValueCents: number): Promise<Response> {
  const { POST } = await import("@/app/api/v1/fixed-assets/[id]/valuation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/fixed-assets/${id}/valuation`, "POST", { schema_version: "1.0.0", current_value_cents: currentValueCents }), { params: Promise.resolve({ id }) });
}

async function maintenanceRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/fixed-assets/[id]/maintenance/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/fixed-assets/${id}/maintenance`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function restorationRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/fixed-assets/[id]/restoration/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/fixed-assets/${id}/restoration`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function disposalRoute(actor: FixtureUser, id: string, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/fixed-assets/[id]/disposal/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/fixed-assets/${id}/disposal`, "POST", { schema_version: "1.0.0", reason }), { params: Promise.resolve({ id }) });
}

async function getRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/fixed-assets/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/fixed-assets/${id}`), { params: Promise.resolve({ id }) });
}

async function listRoute(actor: FixtureUser, assetClass?: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/fixed-assets/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/fixed-assets${assetClass ? `?asset_class=${assetClass}` : ""}`));
}

const immovableA = { schema_version: "1.0.0", asset_class: "IMMOVABLE", asset_code: "IMM-001", category: "BUILDING", description: "Head office building", location_or_address: "1 Asset Street, Windhoek", acquisition_date: "2020-01-15", acquisition_cost_cents: 500_000_00, current_value_cents: 480_000_00 };
const movableA = { schema_version: "1.0.0", asset_class: "MOVABLE", asset_code: "MOV-001", category: "VEHICLE", description: "Delivery truck", location_or_address: "Main warehouse", acquisition_date: "2022-06-01", acquisition_cost_cents: 45_000_00, current_value_cents: 30_000_00 };

describe("Operations > Immovable/Movable Asset Management: RegisterFixedAsset/RecordFixedAssetValuation/FlagMaintenanceFixedAsset/RestoreFixedAsset/DisposeFixedAsset", () => {
  let immovableId: string;
  let movableId: string;

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

  it("registers an immovable asset as ACTIVE", async () => {
    const response = await registerRoute(OWNER_A, immovableA);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.organisation_id).toBe("org-fa-a");
    expect(body.resource.asset_class).toBe("IMMOVABLE");
    expect(body.resource.status).toBe("ACTIVE");
    immovableId = body.resource.id;
  });

  it("registers a movable asset as ACTIVE", async () => {
    const response = await registerRoute(OWNER_A, movableA);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.asset_class).toBe("MOVABLE");
    movableId = body.resource.id;
  });

  it("rejects a category that does not belong to the given asset_class", async () => {
    const response = await registerRoute(OWNER_A, { ...immovableA, asset_code: "IMM-002", category: "VEHICLE" });
    expect(response.status).toBe(422);
  });

  it("rejects an invalid asset_class", async () => {
    const response = await registerRoute(OWNER_A, { ...immovableA, asset_code: "IMM-003", asset_class: "INTANGIBLE" });
    expect(response.status).toBe(422);
  });

  it("refuses a duplicate asset_code within the same organisation", async () => {
    const response = await registerRoute(OWNER_A, { ...movableA, asset_code: "IMM-001", asset_class: "MOVABLE", category: "EQUIPMENT" });
    expect(response.status).toBe(409);
  });

  it("denies registration to a role without fixed-assets:manage", async () => {
    const response = await registerRoute(STAFF, { ...movableA, asset_code: "MOV-999" });
    expect(response.status).toBe(403);
  });

  it("ListFixedAssets filters by asset_class", async () => {
    const immovables = await listRoute(OWNER_A, "IMMOVABLE");
    const immovableBody = await immovables.json();
    expect(immovableBody.resources.map((r: { id: string }) => r.id)).toEqual([immovableId]);

    const movables = await listRoute(OWNER_A, "MOVABLE");
    const movableBody = await movables.json();
    expect(movableBody.resources.map((r: { id: string }) => r.id)).toEqual([movableId]);
  });

  it("ListFixedAssets scopes a tenant actor to their own organisation only", async () => {
    const response = await listRoute(OWNER_A);
    const body = await response.json();
    expect(body.resources).toHaveLength(2);
  });

  it("refuses another organisation's owner reaching into org A's asset", async () => {
    const response = await getRoute(OWNER_B, immovableId);
    expect(response.status).toBe(403);
  });

  it("RecordFixedAssetValuation updates current_value_cents", async () => {
    const response = await valuationRoute(OWNER_A, immovableId, 490_000_00);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.current_value_cents).toBe(490_000_00);
  });

  it("FlagMaintenanceFixedAsset moves ACTIVE -> UNDER_MAINTENANCE", async () => {
    const response = await maintenanceRoute(OWNER_A, movableId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("UNDER_MAINTENANCE");
  });

  it("refuses flagging maintenance twice in a row", async () => {
    const response = await maintenanceRoute(OWNER_A, movableId);
    expect(response.status).toBe(422);
  });

  it("RestoreFixedAsset moves UNDER_MAINTENANCE -> ACTIVE", async () => {
    const response = await restorationRoute(OWNER_A, movableId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("ACTIVE");
  });

  it("DisposeFixedAsset requires a reason and moves ACTIVE -> DISPOSED", async () => {
    const missingReason = await disposalRoute(OWNER_A, movableId, "");
    expect(missingReason.status).toBe(422);

    const response = await disposalRoute(OWNER_A, movableId, "Sold to a third party after fleet downsizing.");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("DISPOSED");
    expect(body.resource.disposed_at).toBeTruthy();
  });

  it("refuses revaluing a disposed asset", async () => {
    const response = await valuationRoute(OWNER_A, movableId, 1_00);
    expect(response.status).toBe(409);
  });

  it("refuses any further transition on a disposed asset", async () => {
    const response = await maintenanceRoute(OWNER_A, movableId);
    expect(response.status).toBe(422);
  });

  it("GetFixedAsset returns the asset to its own organisation", async () => {
    const response = await getRoute(OWNER_A, immovableId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.id).toBe(immovableId);
  });

  it("ListFixedAssets shows every organisation's assets to a national-scope actor", async () => {
    const response = await listRoute(PILOT_ADMIN);
    const body = await response.json();
    expect(body.resources.map((r: { id: string }) => r.id)).toContain(immovableId);
  });

  it("RegisterFixedAsset is idempotent under a repeated idempotency key", async () => {
    const key = crypto.randomUUID();
    const first = await registerRoute(OWNER_A, { ...movableA, asset_code: "MOV-IDEMPOTENT" }, key);
    const second = await registerRoute(OWNER_A, { ...movableA, asset_code: "MOV-IDEMPOTENT" }, key);
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect((await first.json()).resource.id).toBe((await second.json()).resource.id);
  });
});
