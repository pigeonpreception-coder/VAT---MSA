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

export interface ItasIdentityPort {
  status(): Promise<ItasIntegrationStatus>;
  verifyTaxpayer(request: ItasTaxpayerVerificationRequest): Promise<ItasTaxpayerVerificationResult>;
}

export class ItasIntegrationUnavailableError extends Error {
  constructor() {
    super("ITAS taxpayer verification is awaiting a confirmed technical contract and is not available in this environment.");
    this.name = "ItasIntegrationUnavailableError";
  }
}

export class UnconfiguredItasIdentityAdapter implements ItasIdentityPort {
  async status(): Promise<ItasIntegrationStatus> {
    return {
      provider: "ITAS",
      configured: false,
      state: "REQUIRES_ITAS_CONFIRMATION",
      capabilities: ["IDENTITY_FEDERATION", "TAXPAYER_VERIFICATION", "RETURN_SUBMISSION"],
    };
  }

  async verifyTaxpayer(request: ItasTaxpayerVerificationRequest): Promise<ItasTaxpayerVerificationResult> {
    void request;
    throw new ItasIntegrationUnavailableError();
  }
}

export function getItasIdentityPort(): ItasIdentityPort {
  return new UnconfiguredItasIdentityAdapter();
}
