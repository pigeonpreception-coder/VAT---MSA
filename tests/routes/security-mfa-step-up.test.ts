import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { generateTotpCode } from "@/lib/domain/mfa";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Security remediation (2026-08-27), item #2 of SECURITY_GAP_ASSESSMENT.md's
 * prioritised list (CRITICAL): "step-up" was previously two request headers
 * (x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at) the *caller*
 * supplied and lib/security/step-up.ts's requireStepUp trusted verbatim —
 * no application code anywhere ever set those headers on a genuine step-up
 * event, only test fixtures did, so every one of the ~28 "step-up gated"
 * commands in this codebase was effectively ungated. This suite proves the
 * real replacement end to end: EnrollTotp/VerifyTotpEnrollment establish a
 * genuine RFC 6238 credential (lib/domain/mfa.ts), ConfirmStepUp writes a
 * real step_up_events row only after verifying a fresh code (with anti-
 * replay via last_used_counter), and a real step-up-gated command
 * (LinkIdentity — chosen because it also exercises item #1's tenant-scope
 * fix in the same flow) only succeeds once that row exists. Proven through
 * the real route handlers (app/api/v1/identity/mfa/..., .../step-up,
 * .../links) and lib/data/mfa-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-mfa-owner", externalUserId: "ext-mfa-owner", email: "owner@mfa-test.test" };
const COLLEAGUE: FixtureUser = { userId: "usr-mfa-colleague", externalUserId: "ext-mfa-colleague", email: "colleague@mfa-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-mfa", "VAT-MFA-001", "TIN-MFA-001", "MFA Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 MFA Street", "finance@mfa-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-mfa", "tp-mfa", "MFA Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-mfa-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "MFA Test Owner", "TAXPAYER_OWNER", "tp-mfa", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(COLLEAGUE.userId, COLLEAGUE.externalUserId, COLLEAGUE.email, "MFA Test Colleague", "TAXPAYER_ACCOUNTANT", "tp-mfa", "ACTIVE", now),
    ...[OWNER, COLLEAGUE].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-mfa-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function enrollRoute(): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/mfa/totp/route");
  return POST(jsonRequest("https://vat-msa.local/api/v1/identity/mfa/totp", {}));
}

async function verifyEnrollmentRoute(code: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/mfa/totp/verification/route");
  return POST(jsonRequest("https://vat-msa.local/api/v1/identity/mfa/totp/verification", { code }));
}

async function confirmStepUpRoute(code: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/step-up/route");
  return POST(jsonRequest("https://vat-msa.local/api/v1/identity/step-up", { code }));
}

async function assuranceRoute(): Promise<Response> {
  const { GET } = await import("@/app/api/v1/identity/assurance/route");
  return GET(new Request("https://vat-msa.local/api/v1/identity/assurance"));
}

async function linkIdentityRoute(targetUserId: string, subject: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/links/route");
  return POST(jsonRequest("https://vat-msa.local/api/v1/identity/links", { user_id: targetUserId, provider_key: "SITES_WORKSPACE", subject }));
}

describe("Security fix: real server-verified MFA/step-up (EnrollTotp, VerifyTotpEnrollment, ConfirmStepUp)", () => {
  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    // A fixed, controllable clock: TOTP codes are only valid within their
    // 30-second step, so the enrolment-verification code and the
    // step-up-confirmation code must be generated far enough apart in time
    // to land in different steps — otherwise they'd be the same code, and
    // confirmStepUp's anti-replay check (last_used_counter, already
    // consumed by verifyEnrollmentRoute) would reject it, not because
    // replay protection is wrong, but because the test failed to produce a
    // genuinely fresh code. Real time is restored in afterAll.
    vi.setSystemTime(new Date("2026-08-27T09:00:00.000Z"));
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
  });

  afterAll(() => {
    vi.useRealTimers();
    vi.unstubAllEnvs();
  });

  it("reports no MFA enrolment and no step-up before anything has happened", async () => {
    actingAs(OWNER);
    const response = await assuranceRoute();
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.mfaEnrolled).toBe(false);
    expect(body.hasRecentStepUp).toBe(false);
  });

  it("denies confirming step-up before MFA is enrolled at all", async () => {
    actingAs(OWNER);
    const response = await confirmStepUpRoute("000000");
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.errors?.[0]?.code).toBe("MFA_NOT_ACTIVE");
  });

  it("denies a step-up-gated command (LinkIdentity) before any step-up has ever been confirmed", async () => {
    actingAs(OWNER);
    const response = await linkIdentityRoute(COLLEAGUE.userId, "ext-mfa-premature-link-attempt");
    expect(response.status).toBe(403);
  });

  let secret: string;

  it("enrols a TOTP credential, returning a fresh secret and otpauth URI", async () => {
    actingAs(OWNER);
    const response = await enrollRoute();
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.enrollment.secret).toMatch(/^[A-Z2-7]+$/);
    expect(body.enrollment.otpauthUri).toContain(encodeURIComponent(body.enrollment.secret));
    secret = body.enrollment.secret;
  });

  it("refuses to verify enrolment with an incorrect code, leaving the credential unverified", async () => {
    actingAs(OWNER);
    const response = await verifyEnrollmentRoute("000000");
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.errors?.[0]?.code).toBe("CODE_INCORRECT");
    const stillPending = await assuranceRoute();
    expect((await stillPending.json()).mfaEnrolled).toBe(false);
  });

  it("verifies enrolment with the correct code, activating the credential", async () => {
    actingAs(OWNER);
    const code = await generateTotpCode(secret);
    const response = await verifyEnrollmentRoute(code);
    expect(response.status).toBe(200);
    expect((await response.json()).credential.status).toBe("ACTIVE");
    const assurance = await assuranceRoute();
    expect((await assurance.json()).mfaEnrolled).toBe(true);
  });

  it("refuses to re-enrol while a credential is already ACTIVE", async () => {
    actingAs(OWNER);
    const response = await enrollRoute();
    expect(response.status).toBe(409);
  });

  it("denies confirming step-up with an incorrect code", async () => {
    actingAs(OWNER);
    const response = await confirmStepUpRoute("111111");
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.errors?.[0]?.code).toBe("CODE_INCORRECT");
  });

  let usedCode: string;

  it("confirms step-up with a correct, fresh code, and a step-up-gated command (LinkIdentity) then succeeds", async () => {
    actingAs(OWNER);
    // Advance well past the enrolment-verification code's 30-second step so this is a genuinely different, unused code.
    vi.setSystemTime(new Date(Date.now() + 90_000));
    usedCode = await generateTotpCode(secret);
    const stepUpResponse = await confirmStepUpRoute(usedCode);
    expect(stepUpResponse.status).toBe(201);
    const stepUpBody = await stepUpResponse.json();
    expect(stepUpBody.step_up.method).toBe("TOTP");
    expect(Date.parse(stepUpBody.step_up.expiresAt)).toBeGreaterThan(Date.now());

    const assurance = await assuranceRoute();
    expect((await assurance.json()).hasRecentStepUp).toBe(true);

    // Item #1 + item #2 together: a real step-up-gated, tenant-scope-checked command now succeeds.
    const linkResponse = await linkIdentityRoute(COLLEAGUE.userId, "ext-mfa-colleague-linked-device");
    expect(linkResponse.status).toBe(201);
  });

  it("refuses to replay the exact same code a second time (anti-replay)", async () => {
    actingAs(OWNER);
    const response = await confirmStepUpRoute(usedCode);
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.errors?.[0]?.code).toBe("CODE_INCORRECT");
  });
});
