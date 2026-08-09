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
  submitVatReturn(request: ItasVatReturnSubmissionRequest): Promise<ItasVatReturnSubmissionResult>;
}

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

export class ItasIntegrationUnavailableError extends Error {
  constructor(capability = "taxpayer verification") {
    super(`ITAS ${capability} is awaiting a confirmed technical contract and is not available in this environment.`);
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

  async submitVatReturn(request: ItasVatReturnSubmissionRequest): Promise<ItasVatReturnSubmissionResult> {
    void request;
    throw new ItasIntegrationUnavailableError("VAT return submission");
  }
}

export function getItasIdentityPort(): ItasIdentityPort {
  return new UnconfiguredItasIdentityAdapter();
}
