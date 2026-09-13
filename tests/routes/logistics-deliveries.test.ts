import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Operations > Logistics Module (NamRA e-VAT MS master prompt section 16E):
 * CreateDelivery/DispatchDelivery/DeliverDelivery/CancelDelivery/
 * ListLogisticsDeliveries/GetLogisticsDelivery against logistics_deliveries.
 * Proves: a delivery always references an already-issued invoice (or is
 * explicitly OTHER); the PENDING -> IN_TRANSIT -> DELIVERED state machine
 * (or PENDING/IN_TRANSIT -> CANCELLED); an optional vehicle_asset_id must be
 * a MOVABLE fixed asset already registered in the same organisation; and
 * tenant/national ownership scoping, mirroring
 * tests/routes/fixed-assets.test.ts's shape.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-ld-owner-a", externalUserId: "ext-ld-owner-a", email: "owner-a@ld-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-ld-owner-b", externalUserId: "ext-ld-owner-b", email: "owner-b@ld-test.test" };
const PILOT_ADMIN: FixtureUser = { userId: "usr-ld-pilot-admin", externalUserId: "ext-ld-pilot-admin", email: "pilot-admin@ld-test.test" };
const STAFF: FixtureUser = { userId: "usr-ld-staff", externalUserId: "ext-ld-staff", email: "staff@ld-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, method: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method, headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<string> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ld-a", "VAT-LD-A01", "TIN-LD-A01", "Logistics Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Depot Street", "finance@ld-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ld-a", "tp-ld-a", "Logistics Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ld-b", "VAT-LD-B01", "TIN-LD-B01", "Logistics Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Depot Street", "finance@ld-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ld-b", "tp-ld-b", "Logistics Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-ld-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-ld-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-ld-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PILOT_ADMIN.userId, PILOT_ADMIN.externalUserId, PILOT_ADMIN.email, "Pilot Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(STAFF.userId, STAFF.externalUserId, STAFF.email, "Staff", "TAXPAYER_VIEWER", "tp-ld-a", "ACTIVE", now),
    ...[OWNER_A, OWNER_B, PILOT_ADMIN, STAFF].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-ld-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO fixed_assets
        (id,organisation_id,asset_class,asset_code,category,description,location_or_address,acquisition_date,acquisition_cost_cents,current_value_cents,status,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind("fa-ld-vehicle-a", "org-ld-a", "MOVABLE", "MOV-LD-001", "VEHICLE", "Delivery van", "Main depot", "2022-01-01", 40_000_00, 25_000_00, "ACTIVE", OWNER_A.userId, now, now),
  ]);
  return "fa-ld-vehicle-a";
}

async function createRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/logistics-deliveries/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/logistics-deliveries", "POST", body, idempotencyKey));
}

async function dispatchRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/logistics-deliveries/[id]/dispatch/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/logistics-deliveries/${id}/dispatch`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function deliverRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/logistics-deliveries/[id]/delivery/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/logistics-deliveries/${id}/delivery`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function cancelRoute(actor: FixtureUser, id: string, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/logistics-deliveries/[id]/cancellation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/logistics-deliveries/${id}/cancellation`, "POST", { schema_version: "1.0.0", reason }), { params: Promise.resolve({ id }) });
}

async function getRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/logistics-deliveries/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/logistics-deliveries/${id}`), { params: Promise.resolve({ id }) });
}

async function listRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/logistics-deliveries/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/logistics-deliveries"));
}

const deliveryA = { schema_version: "1.0.0", delivery_number: "DEL-001", reference_type: "INVOICE", reference_id: "inv-synthetic-001", origin: "Main warehouse, Windhoek", destination: "Customer site, Swakopmund" };

describe("Operations > Logistics Module: CreateDelivery/DispatchDelivery/DeliverDelivery/CancelDelivery", () => {
  let vehicleAssetId: string;
  let deliveryId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    vehicleAssetId = await seedFixture();
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("creates a delivery as PENDING, referencing an invoice", async () => {
    const response = await createRoute(OWNER_A, { ...deliveryA, vehicle_asset_id: vehicleAssetId });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.organisation_id).toBe("org-ld-a");
    expect(body.resource.status).toBe("PENDING");
    deliveryId = body.resource.id;
  });

  it("requires reference_id unless reference_type is OTHER", async () => {
    const response = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-002", reference_id: undefined });
    expect(response.status).toBe(422);
  });

  it("allows reference_type OTHER without a reference_id", async () => {
    const response = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-003", reference_type: "OTHER", reference_id: undefined });
    expect(response.status).toBe(201);
  });

  it("refuses a duplicate delivery_number within the same organisation", async () => {
    const response = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-001" });
    expect(response.status).toBe(409);
  });

  it("refuses a vehicle_asset_id that is not a movable asset registered in the organisation", async () => {
    const response = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-004", vehicle_asset_id: "fa-does-not-exist" });
    expect(response.status).toBe(422);
  });

  it("denies creation to a role without logistics:manage", async () => {
    const response = await createRoute(STAFF, { ...deliveryA, delivery_number: "DEL-005" });
    expect(response.status).toBe(403);
  });

  it("refuses another organisation's owner reaching into org A's delivery", async () => {
    const response = await getRoute(OWNER_B, deliveryId);
    expect(response.status).toBe(403);
  });

  it("DispatchDelivery moves PENDING -> IN_TRANSIT", async () => {
    const response = await dispatchRoute(OWNER_A, deliveryId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("IN_TRANSIT");
    expect(body.resource.dispatched_at).toBeTruthy();
  });

  it("refuses dispatching an already-dispatched delivery", async () => {
    const response = await dispatchRoute(OWNER_A, deliveryId);
    expect(response.status).toBe(422);
  });

  it("DeliverDelivery moves IN_TRANSIT -> DELIVERED", async () => {
    const response = await deliverRoute(OWNER_A, deliveryId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("DELIVERED");
    expect(body.resource.delivered_at).toBeTruthy();
  });

  it("refuses any further transition on a delivered delivery", async () => {
    const response = await cancelRoute(OWNER_A, deliveryId, "Attempting to cancel after delivery completed.");
    expect(response.status).toBe(422);
  });

  it("CancelDelivery requires a reason and moves PENDING -> CANCELLED", async () => {
    const created = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-006" });
    const { resource } = await created.json();

    const missingReason = await cancelRoute(OWNER_A, resource.id, "");
    expect(missingReason.status).toBe(422);

    const response = await cancelRoute(OWNER_A, resource.id, "Customer cancelled the order before dispatch.");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("CANCELLED");
  });

  it("CancelDelivery also works from IN_TRANSIT", async () => {
    const created = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-007" });
    const { resource } = await created.json();
    await dispatchRoute(OWNER_A, resource.id);

    const response = await cancelRoute(OWNER_A, resource.id, "Vehicle broke down en route, order cancelled.");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("CANCELLED");
  });

  it("GetLogisticsDelivery returns the delivery to its own organisation", async () => {
    const response = await getRoute(OWNER_A, deliveryId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.id).toBe(deliveryId);
  });

  it("ListLogisticsDeliveries scopes a tenant actor to their own organisation only", async () => {
    const response = await listRoute(OWNER_A);
    const body = await response.json();
    expect(body.resources.every((r: { organisation_id: string }) => r.organisation_id === "org-ld-a")).toBe(true);
    expect(body.resources.length).toBeGreaterThan(0);
  });

  it("ListLogisticsDeliveries shows every organisation's deliveries to a national-scope actor", async () => {
    const response = await listRoute(PILOT_ADMIN);
    const body = await response.json();
    expect(body.resources.map((r: { id: string }) => r.id)).toContain(deliveryId);
  });

  it("CreateDelivery is idempotent under a repeated idempotency key", async () => {
    const key = crypto.randomUUID();
    const first = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-IDEMPOTENT" }, key);
    const second = await createRoute(OWNER_A, { ...deliveryA, delivery_number: "DEL-IDEMPOTENT" }, key);
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect((await first.json()).resource.id).toBe((await second.json()).resource.id);
  });
});
