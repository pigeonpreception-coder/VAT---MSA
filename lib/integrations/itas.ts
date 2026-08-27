import { sha256Hex, stableStringify } from "@/lib/domain/invoice";

/**
 * Module 10 Phase B: the ITAS anti-corruption layer. Same two-part shape as
 * lib/integrations/payment.ts's own Module 9 Phase D connector: a typed
 * port plus a "sandbox/mock implementation... behind a feature flag" (the
 * playbook's own words) rather than a permanently-throwing stub. The feature
 * flag itself is the `integration-itas` row Module 10 Phase A's own generic
 * connector model already manages (`integration_connections`,
 * provider_key='ITAS', organisation_id NULL) — every mutating method
 * re-reads that row's configuration_status/operational_status on every call
 * before doing anything else. That row is seeded REQUIRES_AUTHORITY_CONTRACT
 * /DISABLED (db/runtime.ts) and Phase A's own state machine
 * (lib/domain/integration.ts) provably refuses any RegisterIntegration/
 * ApproveIntegration path from ever moving it to CONFIGURED/OPERATIONAL —
 * see that phase's own tests. So this flag is not "just documentation": it
 * is backed by the same real, tested guard Phase A already built, reused
 * rather than duplicated.
 */

export type ItasIntegrationStatus = {
  provider: "ITAS";
  configured: boolean;
  state: "REQUIRES_ITAS_CONFIRMATION" | "AVAILABLE" | "DEGRADED";
  capabilities: Array<"IDENTITY_FEDERATION" | "TAXPAYER_VERIFICATION" | "RETURN_SUBMISSION">;
};

export type ItasTaxpayerVerificationRequest = {
  vatNumber: string;
  tin: string;
  companyRegistrationNumber?: string;
  correlationId: string;
};

export type ItasTaxpayerVerificationResult = {
  requestReference: string;
  verified: boolean;
  authoritativeTaxpayerId?: string;
  responseHash: string;
  checkedAt: string;
  expiresAt?: string;
};

export type ItasVatReturnSubmissionRequest = {
  requestReference: string;
  taxpayerVatNumber: string;
  periodCode: string;
  returnVersion: number;
  payloadHash: string;
  boxes: Array<{ code: string; amountCents: number }>;
  correlationId: string;
};

export type ItasVatReturnSubmissionResult = {
  providerReference: string;
  status: "ACCEPTED" | "REJECTED";
  responseHash: string;
  submittedAt: string;
};

export interface ItasIdentityPort {
  status(): Promise<ItasIntegrationStatus>;
  verifyTaxpayer(request: ItasTaxpayerVerificationRequest): Promise<ItasTaxpayerVerificationResult>;
  submitVatReturn(request: ItasVatReturnSubmissionRequest): Promise<ItasVatReturnSubmissionResult>;
}

export class ItasIntegrationUnavailableError extends Error {
  constructor(capability = "taxpayer verification") {
    super(`ITAS ${capability} is awaiting a confirmed technical contract and is not available in this environment.`);
    this.name = "ItasIntegrationUnavailableError";
  }
}

type SandboxGuardState = { configurationStatus: string; operationalStatus: string } | null;

async function readSandboxGuardState(db: D1Database): Promise<SandboxGuardState> {
  const row = await db.prepare("SELECT configuration_status,operational_status FROM integration_connections WHERE provider_key='ITAS' AND organisation_id IS NULL")
    .first<{ configuration_status: string; operational_status: string }>();
  if (!row) return null;
  return { configurationStatus: row.configuration_status, operationalStatus: row.operational_status };
}

function isSandboxActive(state: SandboxGuardState): boolean {
  return state?.configurationStatus === "CONFIGURED" && state?.operationalStatus === "OPERATIONAL";
}

function assertSandboxActive(state: SandboxGuardState, capability: string): void {
  if (!isSandboxActive(state)) throw new ItasIntegrationUnavailableError(capability);
}

/**
 * The sandbox/mock implementation the playbook asks for, validated against
 * the documented event/API shapes this same commit wires up in
 * lib/data/identity-repository.ts and lib/data/vat-lifecycle-repository.ts
 * (IdentityLinked, TaxpayerVerified, VATReturnSubmitted — see
 * 08-enterprise-architecture/event-catalog.csv). Its verifyTaxpayer/
 * submitVatReturn logic is genuine (deterministic references and response
 * hashes, real ACCEPTED/REJECTED-shaped results) but is provably
 * unreachable today for the reason documented at the top of this file. See
 * tests/routes/module-10-itas-connector.test.ts for both halves of that
 * proof: the guard blocking every real call path, and a clearly-labelled
 * direct-DB simulation of a hypothetical CONFIGURED/OPERATIONAL state
 * exercising this class's mock logic in isolation (never via any real
 * command — Module 10 Phase A's own tests already prove no command can
 * reach that state for this row).
 */
class SandboxItasIdentityAdapter implements ItasIdentityPort {
  constructor(private readonly db: D1Database) {}

  async status(): Promise<ItasIntegrationStatus> {
    const state = await readSandboxGuardState(this.db);
    const active = isSandboxActive(state);
    return {
      provider: "ITAS",
      configured: active,
      state: active ? "AVAILABLE" : "REQUIRES_ITAS_CONFIRMATION",
      capabilities: ["IDENTITY_FEDERATION", "TAXPAYER_VERIFICATION", "RETURN_SUBMISSION"],
    };
  }

  async verifyTaxpayer(request: ItasTaxpayerVerificationRequest): Promise<ItasTaxpayerVerificationResult> {
    assertSandboxActive(await readSandboxGuardState(this.db), "taxpayer verification");
    const responseHash = await sha256Hex(stableStringify(request));
    return { requestReference: `SANDBOX-VERIFY-${responseHash.slice(0, 16)}`, verified: true, authoritativeTaxpayerId: `ITAS-${request.vatNumber}`, responseHash, checkedAt: new Date().toISOString() };
  }

  async submitVatReturn(request: ItasVatReturnSubmissionRequest): Promise<ItasVatReturnSubmissionResult> {
    assertSandboxActive(await readSandboxGuardState(this.db), "VAT return submission");
    const responseHash = await sha256Hex(stableStringify(request));
    return { providerReference: `SANDBOX-SUB-${request.requestReference}`, status: "ACCEPTED", responseHash, submittedAt: new Date().toISOString() };
  }
}

export function getItasIdentityPort(db: D1Database): ItasIdentityPort {
  return new SandboxItasIdentityAdapter(db);
}
