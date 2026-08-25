import { describe, expect, it } from "vitest";
import {
  ControlPlaneValidationError,
  assertLicenseStateTransition,
  assertWorkflowDecision,
  evaluateEntitlement,
  hasRecentStepUp,
  normalizeAdministratorAppointment,
  normalizeCapabilityGrant,
  normalizeEmployeeActivation,
  normalizeLicenseStateChange,
  normalizeLicenseUpgrade,
  normalizeNavigationChildrenQuery,
  normalizeNavigationPreference,
  normalizeOrganisationRole,
  normalizeWorkflowDefinition,
  quarterlyAccessReviewWindow,
} from "@/lib/domain/control-plane";

describe("licence continuity policy", () => {
  it("preserves authorised read and compliance continuity after expiry", () => {
    for (const operationClass of ["READ", "EXPORT", "COMPLIANCE_WRITE", "CORRECTION_WRITE"] as const) {
      const result = evaluateEntitlement({ licenseState: "EXPIRED", featureKey: "CORE_VAT", featureEnabled: true, operationClass, limit: null, used: 0 });
      expect(result.allowed).toBe(true);
      expect(result.obligations).toContain("ENHANCED_AUDIT");
    }
  });

  it("blocks expansion without deleting records after expiry", () => {
    const result = evaluateEntitlement({ licenseState: "EXPIRED", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", limit: 25, used: 3 });
    expect(result.allowed).toBe(false);
    expect(result.code).toBe("LICENSE_EXPIRED");
    expect(result.obligations).toContain("PRESERVE_RECORDS");
  });

  it("enforces numeric limits including reserved capacity", () => {
    const result = evaluateEntitlement({ licenseState: "ACTIVE", featureKey: "USER_SEATS", featureEnabled: true, operationClass: "ADMIN_WRITE", limit: 5, used: 3, reserved: 1, requested: 2 });
    expect(result).toMatchObject({ allowed: false, code: "ENTITLEMENT_LIMIT_EXCEEDED", remaining: 1 });
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

describe("licence lifecycle (Activate/Suspend/Renew/Upgrade)", () => {
  it("normalizes a well-formed state-change action and reason", () => {
    expect(normalizeLicenseStateChange({ action: "activate", reason: "Payment received, lifting the grace-period hold." })).toEqual({
      action: "ACTIVATE",
      reason: "Payment received, lifting the grace-period hold.",
    });
  });

  it("rejects an unsupported action", () => {
    expect(() => normalizeLicenseStateChange({ action: "CANCEL", reason: "Not a supported action here." })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeLicenseStateChange({ action: "SUSPEND", reason: "no" })).toThrowError(ControlPlaneValidationError);
  });

  it("allows ACTIVATE from TRIAL, GRACE_PERIOD, PENDING_RENEWAL and SUSPENDED", () => {
    for (const state of ["TRIAL", "GRACE_PERIOD", "PENDING_RENEWAL", "SUSPENDED"] as const) {
      expect(() => assertLicenseStateTransition("ACTIVATE", state)).not.toThrow();
    }
  });

  it("denies ACTIVATE from the terminal EXPIRED and CANCELLED states", () => {
    for (const state of ["EXPIRED", "CANCELLED"] as const) {
      expect(() => assertLicenseStateTransition("ACTIVATE", state)).toThrowError(ControlPlaneValidationError);
    }
  });

  it("allows SUSPEND from any non-terminal state but denies it once already terminal", () => {
    expect(() => assertLicenseStateTransition("SUSPEND", "ACTIVE")).not.toThrow();
    expect(() => assertLicenseStateTransition("SUSPEND", "EXPIRED")).toThrowError(ControlPlaneValidationError);
  });

  it("allows RENEW even from EXPIRED, unlike ACTIVATE and SUSPEND", () => {
    expect(() => assertLicenseStateTransition("RENEW", "EXPIRED")).not.toThrow();
    expect(() => assertLicenseStateTransition("RENEW", "CANCELLED")).toThrowError(ControlPlaneValidationError);
  });

  it("normalizes and uppercases a licence plan code for Upgrade", () => {
    expect(normalizeLicenseUpgrade({ license_plan_code: "growth-plus" })).toEqual({ licensePlanCode: "GROWTH-PLUS" });
  });

  it("rejects a malformed licence plan code", () => {
    expect(() => normalizeLicenseUpgrade({ license_plan_code: "a" })).toThrowError(ControlPlaneValidationError);
    expect(() => normalizeLicenseUpgrade({ license_plan_code: "" })).toThrowError(ControlPlaneValidationError);
  });
});

describe("administrator appointment (AppointAdministrator)", () => {
  it("normalizes a well-formed appointment, defaulting is_primary to false", () => {
    expect(normalizeAdministratorAppointment({ user_id: "usr-1", administrator_role_code: "finance", approval_reference: "Board resolution 2026-08-01." })).toEqual({
      userId: "usr-1",
      administratorRoleCode: "FINANCE",
      isPrimary: false,
      approvalReference: "Board resolution 2026-08-01.",
    });
  });

  it("accepts an explicit is_primary flag", () => {
    expect(normalizeAdministratorAppointment({ user_id: "usr-1", administrator_role_code: "PRIMARY", is_primary: true, approval_reference: "Primary admin succession." }).isPrimary).toBe(true);
  });

  it("rejects a missing user id", () => {
    expect(() => normalizeAdministratorAppointment({ administrator_role_code: "FINANCE", approval_reference: "Board resolution 2026-08-01." })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects a malformed administrator role code", () => {
    expect(() => normalizeAdministratorAppointment({ user_id: "usr-1", administrator_role_code: "finance scope!", approval_reference: "Board resolution 2026-08-01." })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects an approval reference outside the 5 to 240 character bound", () => {
    expect(() => normalizeAdministratorAppointment({ user_id: "usr-1", administrator_role_code: "FINANCE", approval_reference: "no" })).toThrowError(ControlPlaneValidationError);
  });
});

describe("employee activation (INVITED -> ACTIVE)", () => {
  it("normalizes a well-formed activation", () => {
    expect(normalizeEmployeeActivation({ user_id: "usr-2" })).toEqual({ userId: "usr-2" });
  });

  it("rejects a missing user id", () => {
    expect(() => normalizeEmployeeActivation({})).toThrowError(ControlPlaneValidationError);
  });
});

describe("capability grant (Organisation Authorization GrantCapability)", () => {
  it("normalizes a well-formed grant, uppercasing the capability", () => {
    expect(normalizeCapabilityGrant({ user_id: "usr-3", capability: "buyer" })).toEqual({ userId: "usr-3", capability: "BUYER" });
    expect(normalizeCapabilityGrant({ user_id: "usr-3", capability: "seller" }).capability).toBe("SELLER");
  });

  it("rejects a missing user id", () => {
    expect(() => normalizeCapabilityGrant({ capability: "BUYER" })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects a capability outside BUYER/SELLER", () => {
    expect(() => normalizeCapabilityGrant({ user_id: "usr-3", capability: "ADMIN" })).toThrowError(ControlPlaneValidationError);
  });
});

describe("navigation children query (GetChildren)", () => {
  it("normalizes a well-formed workspace query", () => {
    expect(normalizeNavigationChildrenQuery("workspace", "ws-1")).toEqual({ parentType: "workspace", parentId: "ws-1" });
  });

  it("normalizes a well-formed folder query, lowercasing parent_type", () => {
    expect(normalizeNavigationChildrenQuery("FOLDER", "fld-1")).toEqual({ parentType: "folder", parentId: "fld-1" });
  });

  it("rejects an unsupported parent_type", () => {
    expect(() => normalizeNavigationChildrenQuery("item", "itm-1")).toThrowError(ControlPlaneValidationError);
  });

  it("rejects a missing parent_id", () => {
    expect(() => normalizeNavigationChildrenQuery("workspace", null)).toThrowError(ControlPlaneValidationError);
  });
});

describe("navigation preference (SavePreference)", () => {
  it("normalizes a well-formed preference, serializing the value to JSON", () => {
    expect(normalizeNavigationPreference({ preference_type: "sidebar_collapsed", value: true })).toEqual({
      preferenceType: "sidebar_collapsed",
      value: "true",
    });
    expect(normalizeNavigationPreference({ preference_type: "recent_items", value: ["a", "b"] }).value).toBe('["a","b"]');
  });

  it("rejects a malformed preference_type", () => {
    expect(() => normalizeNavigationPreference({ preference_type: "Has Spaces", value: 1 })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects a missing value", () => {
    expect(() => normalizeNavigationPreference({ preference_type: "sidebar_collapsed" })).toThrowError(ControlPlaneValidationError);
  });

  it("rejects an oversized value", () => {
    expect(() => normalizeNavigationPreference({ preference_type: "big", value: "x".repeat(9_000) })).toThrowError(ControlPlaneValidationError);
  });
});
