import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 5 Phase D: Product/Warehouse CRUD-unstick (both tables were
 * seed-only before this phase, mirroring Phase C's CreateAccount fix for
 * chart_of_accounts), the new atomic TransferStock (previously a "transfer"
 * needed two separate, unlinked RecordStockMovement calls with no atomicity
 * or linkage), and the aggregated GetAvailability/Valuation reports
 * (previously inventory_balances only surfaced as a raw per-row list with
 * no per-product total). Proven through the real route handlers
 * (app/api/v1/products, app/api/v1/warehouses, app/api/v1/inventory/*,
 * dispatched via lib/api/business.ts) and lib/data/business-repository.ts.
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-inv-owner", externalUserId: "ext-inv-owner", email: "owner@inv-test.test" };
const VIEWER: FixtureUser = { userId: "usr-inv-viewer", externalUserId: "ext-inv-viewer", email: "viewer@inv-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-inv-taxpayer", "VAT-INV-001", "TIN-INV-001", "Inventory Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Inventory Street", "finance@inv-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-inv-taxpayer", "tp-inv-taxpayer", "Inventory Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO branches (id,organisation_id,code,name,address,status,is_head_office,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)`)
      .bind("br-inv-head", "org-inv-taxpayer", "HEAD", "Head Office", "1 Inventory Street", "ACTIVE", 1, now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-inv-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-inv-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(VIEWER.userId, VIEWER.externalUserId, VIEWER.email, "Viewer", "TAXPAYER_VIEWER", "tp-inv-taxpayer", "ACTIVE", now),
    ...[OWNER, VIEWER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-inv-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function createProductRoute(body: Record<string, unknown>, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/products/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/products", {
    schema_version: "1.0.0", unit_code: "EA", tax_category: "STANDARD", tax_rate_bps: 1_500, sales_price_cents: 9_900, cost_price_cents: 5_000, ...body,
  }));
}

async function createWarehouseRoute(body: Record<string, unknown>, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/warehouses/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/warehouses", { schema_version: "1.0.0", address: "1 Storage Road", ...body }));
}

async function recordMovementRoute(body: Record<string, unknown>, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/inventory/movements/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/inventory/movements", {
    schema_version: "1.0.0", reference_type: "ADJUSTMENT", reference_id: crypto.randomUUID(), reason: "Fixture stock", ...body,
  }));
}

async function transferStockRoute(body: Record<string, unknown>, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/inventory/transfers/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/inventory/transfers", { schema_version: "1.0.0", reason: "Rebalancing stock", ...body }));
}

async function availabilityRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/inventory/availability/route");
  actingAs(actor);
  const qs = new URLSearchParams(query).toString();
  return GET(new Request(`https://vat-msa.local/api/v1/inventory/availability${qs ? `?${qs}` : ""}`));
}

async function valuationRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/inventory/valuation/route");
  actingAs(actor);
  const qs = new URLSearchParams(query).toString();
  return GET(new Request(`https://vat-msa.local/api/v1/inventory/valuation${qs ? `?${qs}` : ""}`));
}

describe("Module 5 inventory: product/warehouse CRUD, atomic transfer, availability/valuation (Phase D)", () => {
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

  it("creates a product and rejects a duplicate SKU", async () => {
    const created = await createProductRoute({ sku: "TEST-WIDGET", name: "Test Widget" }, OWNER);
    expect(created.status).toBe(201);
    expect((await created.json()).resource.status).toBe("ACTIVE");

    const duplicate = await createProductRoute({ sku: "TEST-WIDGET", name: "Duplicate Widget" }, OWNER);
    expect(duplicate.status).toBe(409);
  });

  it("creates a warehouse referencing a valid branch, and rejects an unowned branch reference", async () => {
    const created = await createWarehouseRoute({ code: "WH-A", name: "Warehouse A", branch_id: "br-inv-head" }, OWNER);
    expect(created.status).toBe(201);
    expect((await created.json()).resource.branch_id).toBe("br-inv-head");

    const badBranch = await createWarehouseRoute({ code: "WH-BAD-BRANCH", name: "Bad Branch Warehouse", branch_id: crypto.randomUUID() }, OWNER);
    expect(badBranch.status).toBe(422);
  });

  it("rejects a duplicate warehouse code", async () => {
    await createWarehouseRoute({ code: "WH-B", name: "Warehouse B" }, OWNER);
    const duplicate = await createWarehouseRoute({ code: "WH-B", name: "Second Warehouse B" }, OWNER);
    expect(duplicate.status).toBe(409);
  });

  it("transfers stock atomically between warehouses, preserving cost basis, and aggregates availability/valuation", async () => {
    const product = await createProductRoute({ sku: "TRANSFER-PRODUCT", name: "Transfer Product" }, OWNER);
    const productId = (await product.json()).resource.id as string;
    const warehouseA = await createWarehouseRoute({ code: "WH-XFER-A", name: "Transfer Warehouse A" }, OWNER);
    const warehouseAId = (await warehouseA.json()).resource.id as string;
    const warehouseB = await createWarehouseRoute({ code: "WH-XFER-B", name: "Transfer Warehouse B" }, OWNER);
    const warehouseBId = (await warehouseB.json()).resource.id as string;

    const receipt = await recordMovementRoute({
      warehouse_id: warehouseAId, product_id: productId, movement_type: "RECEIPT", quantity_micros: 10_000_000, unit_cost_cents: 1_000,
    }, OWNER);
    expect(receipt.status).toBe(201);

    const transfer = await transferStockRoute({ from_warehouse_id: warehouseAId, to_warehouse_id: warehouseBId, product_id: productId, quantity_micros: 4_000_000 }, OWNER);
    expect(transfer.status).toBe(201);
    const transferBody = await transfer.json();
    expect(transferBody.resource.movements).toHaveLength(2);
    expect(transferBody.resource.unit_cost_cents).toBe(1_000);

    const availability = await availabilityRoute(OWNER, { product_id: productId });
    expect(availability.status).toBe(200);
    const availabilityBody = await availability.json();
    expect(availabilityBody.products).toHaveLength(1);
    expect(availabilityBody.products[0].total_quantity_micros).toBe(10_000_000);
    expect(availabilityBody.products[0].by_warehouse).toHaveLength(2);

    const valuation = await valuationRoute(OWNER, { product_id: productId });
    expect(valuation.status).toBe(200);
    const valuationBody = await valuation.json();
    expect(valuationBody.products[0].total_value_cents).toBe(10_000);
    const warehouseBEntry = valuationBody.products[0].by_warehouse.find((w: { warehouse_id: string }) => w.warehouse_id === warehouseBId);
    expect(warehouseBEntry.average_cost_cents).toBe(1_000);
  });

  it("rejects a transfer that would make the source warehouse negative and one between the same warehouse", async () => {
    const product = await createProductRoute({ sku: "NEGATIVE-PRODUCT", name: "Negative Product" }, OWNER);
    const productId = (await product.json()).resource.id as string;
    const warehouseA = await createWarehouseRoute({ code: "WH-NEG-A", name: "Negative Warehouse A" }, OWNER);
    const warehouseAId = (await warehouseA.json()).resource.id as string;
    const warehouseB = await createWarehouseRoute({ code: "WH-NEG-B", name: "Negative Warehouse B" }, OWNER);
    const warehouseBId = (await warehouseB.json()).resource.id as string;

    await recordMovementRoute({ warehouse_id: warehouseAId, product_id: productId, movement_type: "RECEIPT", quantity_micros: 1_000_000, unit_cost_cents: 500 }, OWNER);

    const overdrawn = await transferStockRoute({ from_warehouse_id: warehouseAId, to_warehouse_id: warehouseBId, product_id: productId, quantity_micros: 5_000_000 }, OWNER);
    expect(overdrawn.status).toBe(409);

    const sameWarehouse = await transferStockRoute({ from_warehouse_id: warehouseAId, to_warehouse_id: warehouseAId, product_id: productId, quantity_micros: 1 }, OWNER);
    expect(sameWarehouse.status).toBe(422);
  });

  it("denies an actor without inventory:manage from creating products/warehouses or transferring stock, but still allows availability/valuation reads", async () => {
    const createProduct = await createProductRoute({ sku: "DENIED-PRODUCT", name: "Denied Product" }, VIEWER);
    expect(createProduct.status).toBe(403);
    const createWarehouseResp = await createWarehouseRoute({ code: "WH-DENIED", name: "Denied Warehouse" }, VIEWER);
    expect(createWarehouseResp.status).toBe(403);
    const transferResp = await transferStockRoute({ from_warehouse_id: crypto.randomUUID(), to_warehouse_id: crypto.randomUUID(), product_id: crypto.randomUUID(), quantity_micros: 1 }, VIEWER);
    expect(transferResp.status).toBe(403);

    const availability = await availabilityRoute(VIEWER);
    expect(availability.status).toBe(200);
    const valuation = await valuationRoute(VIEWER);
    expect(valuation.status).toBe(200);
  });
});
