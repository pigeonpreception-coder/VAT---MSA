import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 7 Phase D: Analytics was greenfield before this phase — a
 * 2026-08-26 audit confirmed nothing beyond the "ARCHITECTURE ONLY" label
 * existed anywhere in this codebase. This deployment has no separate
 * governed read replica (the same D1 binding backs both the live fiscal
 * write path and every read), so PublishDataProduct's "never the live
 * fiscal write store" requirement is enforced the strongest way actually
 * available here: RunModel may only be fed by an already-PUBLISHED,
 * already-reconciled report run (Module 7 Phase C), never a live query
 * against invoices/audit_cases/etc. directly. Proven through the real route
 * handlers (app/api/v1/analytics/..., dispatched via lib/api/platform.ts)
 * and lib/data/platform-repository.ts's runAnalyticsModel/
 * publishDataProduct/queryApprovedMetrics/listAnomalyCandidates. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const NAMRA_OFFICER: FixtureUser = { userId: "usr-an-namra", externalUserId: "ext-an-namra", email: "namra@an-test.test" };
const TAXPAYER_OWNER: FixtureUser = { userId: "usr-an-owner", externalUserId: "ext-an-owner", email: "owner@an-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-an-a", "VAT-AN-A", "TIN-AN-A", "Analytics Test Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Analytics Street", "finance@an-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-an-a", "tp-an-a", "Analytics Test Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-an-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-an-a", "ACTIVE", now),
    ...[NAMRA_OFFICER, TAXPAYER_OWNER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-an-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
      VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind("report-def-an-source", "AN_TEST_SOURCE", "Analytics test source report", "EXECUTIVE", "Source report for the analytics foundation test.", "CONFIDENTIAL", now, "DAILY", "aggregation, disclosure controls"),
    db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
      VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind("report-def-an-other", "AN_TEST_OTHER", "A different report definition", "EXECUTIVE", "A report definition the data product does not declare as its source.", "CONFIDENTIAL", now, "DAILY", "aggregation, disclosure controls"),
    // Not yet published — used to prove RunModel refuses an unpublished run.
    db.prepare(`INSERT INTO report_runs (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code)
      VALUES (?,?,NULL,NULL,'{}','COMPLETED_INLINE',2,'{"total_cents":500000,"open_cases":1}',NULL,?,?,?,NULL,NULL)`)
      .bind("report-run-an-unpublished", "report-def-an-source", NAMRA_OFFICER.userId, now, now),
    // Published, but against a different report definition than the data product declares.
    db.prepare(`INSERT INTO report_runs (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,published_by,published_at)
      VALUES (?,?,NULL,NULL,'{}','PUBLISHED',2,'{"total_cents":500000,"open_cases":1}',NULL,?,?,?,NULL,NULL,?,?)`)
      .bind("report-run-an-wrong-source", "report-def-an-other", NAMRA_OFFICER.userId, now, now, NAMRA_OFFICER.userId, now),
    // Published, minimum-cell suppressed — must not be allowed to feed a certified model.
    db.prepare(`INSERT INTO report_runs (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,published_by,published_at)
      VALUES (?,?,NULL,NULL,'{}','PUBLISHED',1,'{"total_cents":0,"open_cases":0,"suppressed":true}',NULL,?,?,?,NULL,NULL,?,?)`)
      .bind("report-run-an-suppressed", "report-def-an-source", NAMRA_OFFICER.userId, now, now, NAMRA_OFFICER.userId, now),
    // Published, valid first source for RunModel/PublishDataProduct.
    db.prepare(`INSERT INTO report_runs (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,published_by,published_at)
      VALUES (?,?,NULL,NULL,'{}','PUBLISHED',2,'{"total_cents":1000000,"open_cases":1}',NULL,?,?,?,NULL,NULL,?,?)`)
      .bind("report-run-an-first", "report-def-an-source", NAMRA_OFFICER.userId, now, now, NAMRA_OFFICER.userId, now),
    // Published, second source with a 60% jump in total_cents (should trigger the 20%-threshold revenue metric, but not the unchanged open_cases metric).
    db.prepare(`INSERT INTO report_runs (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,published_by,published_at)
      VALUES (?,?,NULL,NULL,'{}','PUBLISHED',2,'{"total_cents":1600000,"open_cases":1}',NULL,?,?,?,NULL,NULL,?,?)`)
      .bind("report-run-an-second", "report-def-an-source", NAMRA_OFFICER.userId, now, now, NAMRA_OFFICER.userId, now),
    db.prepare(`INSERT INTO data_products (id,code,name,description,source_report_definition_id,status,created_at)
      VALUES (?,?,?,?,?,'ACTIVE',?)`).bind("dp-an-test", "AN_TEST_TRENDS", "Analytics test trends", "Test data product for the analytics foundation phase.", "report-def-an-source", now),
    db.prepare(`INSERT INTO data_product_lineage (id,data_product_id,source_type,source_id,source_label,recorded_at)
      VALUES (?,?,?,?,?,?)`).bind("lineage-an-0001", "dp-an-test", "REPORT_DEFINITION", "report-def-an-source", "AN_TEST_SOURCE", now),
    db.prepare(`INSERT INTO metrics (id,code,name,data_product_id,field,unit,status,anomaly_threshold_pct,created_at)
      VALUES (?,?,?,?,?,?,'CERTIFIED',20,?)`).bind("metric-an-revenue", "AN_TEST_REVENUE_CENTS", "Test revenue", "dp-an-test", "total_cents", "CENTS", now),
    db.prepare(`INSERT INTO metrics (id,code,name,data_product_id,field,unit,status,anomaly_threshold_pct,created_at)
      VALUES (?,?,?,?,?,?,'CERTIFIED',20,?)`).bind("metric-an-cases", "AN_TEST_OPEN_CASES", "Test open cases", "dp-an-test", "open_cases", "COUNT", now),
  ]);
}

async function listDataProductsRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/analytics/data-products/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/analytics/data-products"));
}

async function runModelRoute(dataProductId: string, actor: FixtureUser, reportRunId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/analytics/data-products/[id]/model-runs/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/analytics/data-products/${dataProductId}/model-runs`, { schema_version: "1.0.0", report_run_id: reportRunId }), { params: Promise.resolve({ id: dataProductId }) });
}

async function publishDataProductRoute(dataProductId: string, actor: FixtureUser, modelRunId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/analytics/data-products/[id]/publications/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/analytics/data-products/${dataProductId}/publications`, { schema_version: "1.0.0", model_run_id: modelRunId }), { params: Promise.resolve({ id: dataProductId }) });
}

async function metricsRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/analytics/metrics/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/analytics/metrics${query}`));
}

async function anomaliesRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/analytics/anomalies/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/analytics/anomalies${query}`));
}

describe("Module 7 analytics foundation: DataProduct/ModelRun/publication/metrics/anomalies (Phase D)", () => {
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

  it("lists the seeded data product with its lineage and certified metrics, with no snapshot yet", async () => {
    const response = await listDataProductsRoute(TAXPAYER_OWNER);
    expect(response.status).toBe(200);
    const body = await response.json();
    const product = body.data_products.find((item: { code: string }) => item.code === "AN_TEST_TRENDS");
    expect(product.source.report_code).toBe("AN_TEST_SOURCE");
    expect(product.lineage).toEqual([{ source_type: "REPORT_DEFINITION", source_id: "report-def-an-source", source_label: "AN_TEST_SOURCE" }]);
    expect(product.certified_metrics).toHaveLength(2);
    expect(product.latest_snapshot).toBeNull();
  });

  it("denies RunModel and PublishDataProduct to a non-national actor", async () => {
    const runModel = await runModelRoute("dp-an-test", TAXPAYER_OWNER, "report-run-an-first");
    expect(runModel.status).toBe(403);
    const publish = await publishDataProductRoute("dp-an-test", TAXPAYER_OWNER, crypto.randomUUID());
    expect(publish.status).toBe(403);
  });

  it("refuses RunModel against a report run that is not published, that belongs to a different source definition, or that is minimum-cell suppressed", async () => {
    const unpublished = await runModelRoute("dp-an-test", NAMRA_OFFICER, "report-run-an-unpublished");
    expect(unpublished.status).toBe(409);

    const wrongSource = await runModelRoute("dp-an-test", NAMRA_OFFICER, "report-run-an-wrong-source");
    expect(wrongSource.status).toBe(422);

    const suppressed = await runModelRoute("dp-an-test", NAMRA_OFFICER, "report-run-an-suppressed");
    expect(suppressed.status).toBe(409);
  });

  it("returns 404 for an unknown data product or report run", async () => {
    const unknownProduct = await runModelRoute(crypto.randomUUID(), NAMRA_OFFICER, "report-run-an-first");
    expect(unknownProduct.status).toBe(404);
    const unknownRun = await runModelRoute("dp-an-test", NAMRA_OFFICER, crypto.randomUUID());
    expect(unknownRun.status).toBe(404);
  });

  it("runs a model from a published report run and publishes it as the data product's first snapshot, with no anomaly on the first publish", async () => {
    const runResponse = await runModelRoute("dp-an-test", NAMRA_OFFICER, "report-run-an-first");
    expect(runResponse.status).toBe(201);
    const modelRunId = (await runResponse.json()).model_run.id as string;

    const publishResponse = await publishDataProductRoute("dp-an-test", NAMRA_OFFICER, modelRunId);
    expect(publishResponse.status).toBe(201);
    const snapshot = (await publishResponse.json()).snapshot;
    expect(JSON.parse(snapshot.snapshot)).toEqual({ total_cents: 1000000, open_cases: 1 });

    const republish = await publishDataProductRoute("dp-an-test", NAMRA_OFFICER, modelRunId);
    expect(republish.status).toBe(409);

    const anomaliesAfterFirst = await anomaliesRoute(NAMRA_OFFICER, "?data_product_id=dp-an-test");
    expect((await anomaliesAfterFirst.json()).anomalies).toHaveLength(0);
  });

  it("detects an anomaly on a metric that moves beyond its threshold, but not on one that stays flat", async () => {
    const runResponse = await runModelRoute("dp-an-test", NAMRA_OFFICER, "report-run-an-second");
    const modelRunId = (await runResponse.json()).model_run.id as string;
    const publishResponse = await publishDataProductRoute("dp-an-test", NAMRA_OFFICER, modelRunId);
    expect(publishResponse.status).toBe(201);

    const anomalies = await anomaliesRoute(NAMRA_OFFICER, "?data_product_id=dp-an-test");
    const anomalyBody = await anomalies.json();
    expect(anomalyBody.anomalies).toHaveLength(1);
    expect(anomalyBody.anomalies[0].metric_code).toBe("AN_TEST_REVENUE_CENTS");
    expect(anomalyBody.anomalies[0].previous_value).toBe(1000000);
    expect(anomalyBody.anomalies[0].current_value).toBe(1600000);
    expect(anomalyBody.anomalies[0].pct_change).toBeCloseTo(60, 5);
  });

  it("queries approved metrics with their current value from the latest snapshot", async () => {
    const response = await metricsRoute(NAMRA_OFFICER, "?data_product_id=dp-an-test");
    expect(response.status).toBe(200);
    const body = await response.json();
    const revenue = body.metrics.find((metric: { code: string }) => metric.code === "AN_TEST_REVENUE_CENTS");
    expect(revenue.value).toBe(1600000);
    expect(revenue.status).toBe("AVAILABLE");
    expect(revenue.data_product_code).toBe("AN_TEST_TRENDS");

    const filtered = await metricsRoute(NAMRA_OFFICER, "?code=AN_TEST_OPEN_CASES");
    const filteredBody = await filtered.json();
    expect(filteredBody.metrics).toHaveLength(1);
    expect(filteredBody.metrics[0].code).toBe("AN_TEST_OPEN_CASES");
  });

  it("reflects the latest published snapshot back on the data product list", async () => {
    const response = await listDataProductsRoute(TAXPAYER_OWNER);
    const body = await response.json();
    const product = body.data_products.find((item: { code: string }) => item.code === "AN_TEST_TRENDS");
    expect(product.latest_snapshot.snapshot).toEqual({ total_cents: 1600000, open_cases: 1 });
  });
});
