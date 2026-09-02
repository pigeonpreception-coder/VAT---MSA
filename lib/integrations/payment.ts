import { sha256Hex, stableStringify } from "@/lib/domain/invoice";

/**
 * Module 9 Phase D: the Payment connector port, mirroring
 * lib/integrations/itas.ts's shape (typed port + an unconfigured-by-default
 * adapter) with one deliberate structural difference. ITAS's adapter is a
 * hardcoded, environment-agnostic "always unavailable" class — there is no
 * configuration anywhere that could ever make it available, because ITAS
 * genuinely does not exist as a reachable system yet. Payment is different:
 * the playbook explicitly calls for "a sandbox/mock implementation," not
 * just a typed stub, so this adapter contains real (if trivial) mock
 * logic — but every mutating method re-reads its authorisation state from
 * `service_components` (component_key='PAYMENT_CONNECTOR') on every single
 * call before doing anything else, rather than trusting a constructor-time
 * or cached flag. That row is seeded DISABLED (see db/runtime.ts) and
 * nothing anywhere in this codebase ever writes to it — GetPaymentConnectorPort
 * is the FIRST and ONLY runtime consumer of service_components as an actual
 * guard (previously it was read only for display, see
 * lib/data/platform-repository.ts). This is the "explicit environment guard
 * that refuses to run outside sandbox configuration — not just
 * documentation" the playbook's Phase D watch-out demands.
 */

export type PaymentConnectorStatus = {
  provider: "SANDBOX_PAYMENT_CONNECTOR";
  configured: boolean;
  state: "REQUIRES_AUTHORITY_CONTRACT" | "SANDBOX_ACTIVE" | "DEGRADED";
  capabilities: Array<"RECORD_PAYMENT" | "ALLOCATE_PAYMENT">;
};

export type RecordPaymentRequest = {
  requestReference: string;
  refundClaimId: string;
  taxpayerId: string;
  amountCents: number;
  currency: string;
  beneficiaryReferenceMasked: string;
  provider: string;
  correlationId: string;
};

export type RecordPaymentResult = {
  providerReference: string;
  status: "INITIATED" | "REJECTED";
  responseHash: string;
  submittedAt: string;
};

export type AllocatePaymentRequest = {
  requestReference: string;
  paymentInstructionId: string;
  settlementReference: string;
  settledAmountCents: number;
  correlationId: string;
};

export type AllocatePaymentResult = {
  settlementReference: string;
  status: "SETTLED" | "REJECTED";
  responseHash: string;
  settledAt: string;
};

export interface PaymentConnectorPort {
  status(): Promise<PaymentConnectorStatus>;
  recordPayment(request: RecordPaymentRequest): Promise<RecordPaymentResult>;
  allocatePayment(request: AllocatePaymentRequest): Promise<AllocatePaymentResult>;
}

export class PaymentIntegrationUnavailableError extends Error {
  constructor(capability = "recording") {
    super(`Payment ${capability} is DISABLED PENDING AUTHORITY and is not available in this environment.`);
    this.name = "PaymentIntegrationUnavailableError";
  }
}

type SandboxGuardState = { configurationStatus: string; operationalStatus: string } | null;

async function readSandboxGuardState(db: D1Database): Promise<SandboxGuardState> {
  const row = await db.prepare("SELECT configuration_status,operational_status FROM service_components WHERE component_key='PAYMENT_CONNECTOR'")
    .first<{ configuration_status: string; operational_status: string }>();
  if (!row) return null;
  return { configurationStatus: row.configuration_status, operationalStatus: row.operational_status };
}

function isSandboxActive(state: SandboxGuardState): boolean {
  return state?.configurationStatus === "SANDBOX_CONFIGURED" && state?.operationalStatus === "SANDBOX_ACTIVE";
}

function assertSandboxActive(state: SandboxGuardState, capability: string): void {
  if (!isSandboxActive(state)) throw new PaymentIntegrationUnavailableError(capability);
}

/**
 * The sandbox/mock implementation the playbook asks for. Its recordPayment
 * and allocatePayment logic below is genuine (deterministic reference and
 * response hash, real status transitions) — but is provably unreachable
 * today because assertSandboxActive's guard runs first on every call and
 * `component-payment`'s seed row never satisfies it. See
 * tests/routes/module-9-payment-connector.test.ts for both halves of that
 * proof: the guard blocking every real command path, and a clearly-labelled
 * direct-DB simulation of a hypothetical SANDBOX_ACTIVE state exercising
 * this class's mock logic in isolation (never via any real command — no
 * command anywhere can reach that state).
 */
class SandboxPaymentConnector implements PaymentConnectorPort {
  constructor(private readonly db: D1Database) {}

  async status(): Promise<PaymentConnectorStatus> {
    const state = await readSandboxGuardState(this.db);
    const active = isSandboxActive(state);
    return {
      provider: "SANDBOX_PAYMENT_CONNECTOR",
      configured: active,
      state: active ? "SANDBOX_ACTIVE" : "REQUIRES_AUTHORITY_CONTRACT",
      capabilities: ["RECORD_PAYMENT", "ALLOCATE_PAYMENT"],
    };
  }

  async recordPayment(request: RecordPaymentRequest): Promise<RecordPaymentResult> {
    assertSandboxActive(await readSandboxGuardState(this.db), "recording");
    const responseHash = await sha256Hex(stableStringify(request));
    return { providerReference: `SANDBOX-${request.requestReference}`, status: "INITIATED", responseHash, submittedAt: new Date().toISOString() };
  }

  async allocatePayment(request: AllocatePaymentRequest): Promise<AllocatePaymentResult> {
    assertSandboxActive(await readSandboxGuardState(this.db), "allocation");
    const responseHash = await sha256Hex(stableStringify(request));
    return { settlementReference: request.settlementReference, status: "SETTLED", responseHash, settledAt: new Date().toISOString() };
  }
}

export function getPaymentConnectorPort(db: D1Database): PaymentConnectorPort {
  return new SandboxPaymentConnector(db);
}
