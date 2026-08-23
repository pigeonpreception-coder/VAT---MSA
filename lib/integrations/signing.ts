import { sha256Hex } from "@/lib/domain/invoice";

export class SigningUnavailableError extends Error {
  constructor() {
    super("A production certificate signer has not been configured.");
    this.name = "SigningUnavailableError";
  }
}

export type CertificateSignature = {
  signature: string;
  profile: string;
};

export async function signCertificationHash(certificationHash: string): Promise<CertificateSignature> {
  if (process.env.NODE_ENV === "production") {
    // Phase 0 deliberately fails closed until an approved HSM/KMS adapter and key
    // ownership process are configured. A development digest is never a valid
    // production certificate signature.
    throw new SigningUnavailableError();
  }

  return {
    signature: `DEV.${await sha256Hex(`VAT-MSA-LOCAL-ONLY:${certificationHash}`)}`,
    profile: "DEV-SHA256-LOCAL-ONLY",
  };
}
