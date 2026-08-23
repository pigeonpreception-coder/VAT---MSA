import { describe, expect, it } from "vitest";
import {
  ControlPlaneValidationError,
  assertWorkflowDecision,
  evaluateEntitlement,
  hasRecentStepUp,
  normalizeOrganisationRole,
  normalizeWorkflowDefinition,
  quarterlyAccessReviewWindow,
} from "@/lib/domain/control-plane";

describe("licence continuity policy", () => {
  it("preserves authorised read and compliance continuity after expiry", () => {
    for (const operationClass of ["READ", "EXPORT", "COMPLIANCE_WRITE", "CORRECTION_WRITE"] as const) {
      const result = evaluateEntitlement({ licenseState: "EXPIRED", featureKey: "CORE_VAT", featureEnabled: true, operationClass, capacityMode: "NOT_APPLICABLE", limit: null, used: 0 });
      expect(result.allowed).toBe(true);
      expect(result.obligations).toContain("ENHANCED_AUDIT");
    }
  });

  it("blocks expansion without deleting records after expiry", () => {
    const result = evaluateEntitlement({ licenseState: "EXPIRED", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", capacityMode: "FINITE", limit: 25, used: 3 });
    expect(result.allowed).toBe(false);
    expect(result.code).toBe("LICENSE_EXPIRED");
    expect(result.obligations).toContain("PRESERVE_RECORDS");
  });

  it("enforces numeric limits including reserved capacity", () => {
    const result = evaluateEntitlement({ licenseState: "ACTIVE", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", capacityMode: "FINITE", limit: 5, used: 3, reserved: 1, requested: 2 });
    expect(result).toMatchObject({ allowed: false, code: "ENTITLEMENT_LIMIT_EXCEEDED", remaining: 1 });
  });

  it("requires unlimited capacity to be represented explicitly", () => {
    expect(evaluateEntitlement({ licenseState: "ACTIVE", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", capacityMode: "UNLIMITED", limit: null, used: 100_000, requested: 1 })).toMatchObject({ allowed: true, remaining: null });
    expect(evaluateEntitlement({ licenseState: "ACTIVE", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", capacityMode: "NOT_APPLICABLE", limit: 999_999, used: 1 })).toMatchObject({ allowed: false, code: "ENTITLEMENT_CONFIGURATION_INVALID" });
  });
});

describe("organisation access and workflow safety", () => {
  it("rejects protected permissions in tenant-defined roles", () => {
    expect(() => normalizeOrganisationRole({ name: "Unsafe role", permissions: ["platform:manage"] })).toThrowError(ControlPlaneValidationError);
  });

  it("accepts only typed workflow nodes, actions and conditions", () => {
    const workflow = normalizeWorkflowDefinition({
      name: "Purchase approval",
      domain_action: "PURCHASE_REQUEST",
      nodes: [
        { id: "start", type: "START", label: "Submitted" },
        { id: "review", type: "APPROVAL", label: "Review", assignee_type: "ROLE", assignee_ref: "finance" },
        { id: "end", type: "END", label: "Complete" },
      ],
      transitions: [{ from: "start", to: "review", condition: { field: "amount_cents", operator: "GT", value: 100_000 } }, { from: "review", to: "end" }],
    });
    expect(workflow.domainAction).toBe("PURCHASE_REQUEST");
    expect(workflow.transitions[0].condition?.field).toBe("amount_cents");
    expect(() => normalizeWorkflowDefinition({ ...workflow, domain_action: "PURCHASE_REQUEST", transitions: [{ from: "start", to: "end", condition: { field: "script", operator: "EQ", value: "process.exit()" } }] })).toThrowError(ControlPlaneValidationError);
  });

  it("denies self-approval and every emergency override", () => {
    expect(() => assertWorkflowDecision({ actorId: "user-1", initiatedBy: "user-1", decision: "APPROVE" })).toThrowError(/cannot approve/i);
    expect(() => assertWorkflowDecision({ actorId: "user-2", initiatedBy: "user-1", decision: "APPROVE", emergencyOverride: true })).toThrowError(/override is disabled/i);
  });

  it("requires a fresh MFA step-up assertion", () => {
    const now = Date.parse("2026-08-10T10:05:00Z");
    expect(hasRecentStepUp({ assurance: "MFA_STEP_UP", reauthenticatedAt: "2026-08-10T10:02:00Z", now })).toBe(true);
    expect(hasRecentStepUp({ assurance: "MFA_STEP_UP", reauthenticatedAt: "2026-08-10T09:50:00Z", now })).toBe(false);
    expect(hasRecentStepUp({ assurance: "PASSWORD", reauthenticatedAt: "2026-08-10T10:04:00Z", now })).toBe(false);
  });

  it("calculates an exact UTC quarterly review window", () => {
    expect(quarterlyAccessReviewWindow(new Date("2026-08-10T12:00:00Z"))).toEqual({
      key: "2026-Q3", periodStart: "2026-07-01", dueAt: "2026-09-30T23:59:59.000Z",
    });
  });
});
