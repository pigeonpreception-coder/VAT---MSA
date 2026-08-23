import { describe, expect, it } from "vitest";
import {
  AuthorityGovernanceValidationError,
  normalizeAuthorityOnboardingDecision,
  normalizeAuthorityOnboardingSubmission,
} from "@/lib/domain/authority-governance";

describe("Tax Authority governance commands", () => {
  it("normalizes a bounded local-staging onboarding request", () => {
    expect(normalizeAuthorityOnboardingSubmission({
      schema_version: "1.0.0",
      tax_authority_id: "tax-authority-na-namra",
      target_environment: "local_staging",
      purpose: "Validate the synthetic governance workflow before any production activation.",
    })).toEqual({
      schema_version: "1.0.0",
      tax_authority_id: "tax-authority-na-namra",
      target_environment: "LOCAL_STAGING",
      purpose: "Validate the synthetic governance workflow before any production activation.",
    });
  });

  it("accepts a production request only as a governance request with explicit evidence references", () => {
    expect(normalizeAuthorityOnboardingSubmission({
      schema_version: "1.0.0",
      tax_authority_id: "authority-na",
      target_environment: "PRODUCTION",
      purpose: "Request controlled production-readiness review without activating the authority.",
      evidence_bundle_hash: "sha256:0123456789abcdef0123456789abcdef0123456789abcdef",
      readiness_reference: "PR-013-AUTHORITY-READINESS",
    })).toMatchObject({ target_environment: "PRODUCTION", readiness_reference: "PR-013-AUTHORITY-READINESS" });
  });

  it("rejects mass-assignment and weak evidence references", () => {
    expect(() => normalizeAuthorityOnboardingSubmission({
      schema_version: "1.0.0",
      tax_authority_id: "authority-na",
      target_environment: "PRODUCTION",
      purpose: "Attempt an unsafe production activation through input mass assignment.",
      status: "PRODUCTION_ACTIVATED",
    })).toThrow(AuthorityGovernanceValidationError);
    expect(() => normalizeAuthorityOnboardingSubmission({
      schema_version: "1.0.0",
      tax_authority_id: "authority-na",
      target_environment: "PRODUCTION",
      purpose: "Attempt a production request with an evidence reference that is too weak.",
      evidence_bundle_hash: "short-hash",
    })).toThrow(/at least 32/i);
  });

  it("limits the local decision command to independent staging approval or rejection", () => {
    expect(normalizeAuthorityOnboardingDecision({
      schema_version: "1.0.0",
      decision: "approve_local_staging",
      reason: "Independent synthetic review completed with no production effect.",
    })).toEqual({
      schema_version: "1.0.0",
      decision: "APPROVE_LOCAL_STAGING",
      reason: "Independent synthetic review completed with no production effect.",
    });
    expect(() => normalizeAuthorityOnboardingDecision({
      schema_version: "1.0.0",
      decision: "PRODUCTION_ACTIVATE",
      reason: "This command must not expose production activation.",
    })).toThrow(/APPROVE_LOCAL_STAGING or REJECT/);
  });
});
