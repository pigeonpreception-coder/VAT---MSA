import { describe, expect, it } from "vitest";
import {
  AccessDeniedError,
  getUserAccess,
  hasPermission,
  isNationalScope,
  requirePermission,
  requireTaxpayerScope,
  resolveEffectivePermissions,
} from "@/lib/domain/access";
import type { UserContext } from "@/lib/domain/types";

function user(overrides: Partial<UserContext> = {}): UserContext {
  return {
    userId: "user-1",
    email: "user@example.com",
    displayName: "Test User",
    role: "TAXPAYER_STAFF",
    taxpayerId: "taxpayer-1",
    organisationId: "org-1",
    capabilities: [],
    dynamicPermissions: [],
    isDevelopmentIdentity: false,
    ...overrides,
  };
}

describe("effective permission resolution", () => {
  it("unions static role permissions with tenant-defined dynamic permissions, deduped and sorted", () => {
    const actor = user({ role: "TAXPAYER_STAFF", dynamicPermissions: ["invoices:read", "custom-role:approve"] });
    const permissions = resolveEffectivePermissions(actor);
    expect(permissions).toContain("invoices:read"); // present in both the static role set and dynamicPermissions
    expect(permissions).toContain("custom-role:approve"); // dynamic-only
    expect(permissions).toContain("commercial:read"); // static-only
    expect(permissions.filter((permission) => permission === "invoices:read")).toHaveLength(1);
    expect(permissions).toEqual([...permissions].sort());
  });

  it("falls back to dynamic permissions alone for a role with no static grant", () => {
    const actor = user({ role: "UNMAPPED_ROLE", dynamicPermissions: ["custom:read"] });
    expect(resolveEffectivePermissions(actor)).toEqual(["custom:read"]);
  });

  it("returns an empty set for an unmapped role with no dynamic grants", () => {
    const actor = user({ role: "UNMAPPED_ROLE", dynamicPermissions: [] });
    expect(resolveEffectivePermissions(actor)).toEqual([]);
  });
});

describe("hasPermission / requirePermission", () => {
  it("grants access via the static role map", () => {
    expect(hasPermission(user({ role: "TAXPAYER_STAFF" }), "invoices:read")).toBe(true);
  });

  it("grants access via a tenant-defined dynamic permission not present in any static role", () => {
    expect(hasPermission(user({ role: "TAXPAYER_STAFF", dynamicPermissions: ["custom-role:approve"] }), "custom-role:approve")).toBe(true);
  });

  it("denies and throws for a permission the role and dynamic grants do not include", () => {
    const actor = user({ role: "TAXPAYER_VIEWER" });
    expect(hasPermission(actor, "administration:manage")).toBe(false);
    expect(() => requirePermission(actor, "administration:manage")).toThrowError(AccessDeniedError);
  });
});

describe("national scope and tenant isolation", () => {
  it("treats a NamRA/pilot role scoped to no single taxpayer as national scope", () => {
    expect(isNationalScope(user({ role: "NAMRA_AUDITOR", taxpayerId: null }))).toBe(true);
  });

  it("does not grant national scope to a NamRA role that is still pinned to one taxpayer", () => {
    expect(isNationalScope(user({ role: "NAMRA_AUDITOR", taxpayerId: "taxpayer-1" }))).toBe(false);
  });

  it("does not grant national scope to a taxpayer-side role regardless of taxpayerId", () => {
    expect(isNationalScope(user({ role: "TAXPAYER_OWNER", taxpayerId: null }))).toBe(false);
  });

  it("rejects cross-tenant access for a non-national actor", () => {
    const actor = user({ role: "TAXPAYER_OWNER", taxpayerId: "taxpayer-1" });
    expect(() => requireTaxpayerScope(actor, "taxpayer-2")).toThrowError(AccessDeniedError);
  });

  it("allows same-tenant access for a non-national actor", () => {
    const actor = user({ role: "TAXPAYER_OWNER", taxpayerId: "taxpayer-1" });
    expect(() => requireTaxpayerScope(actor, "taxpayer-1")).not.toThrow();
  });

  it("allows a national-scope actor to reach any taxpayer's record", () => {
    const actor = user({ role: "NAMRA_AUDITOR", taxpayerId: null });
    expect(() => requireTaxpayerScope(actor, "taxpayer-99")).not.toThrow();
  });
});

describe("getUserAccess (Module 1 GetUserAccess query)", () => {
  it("returns the full effective RBAC+ABAC predicate set for a session", () => {
    const actor = user({
      role: "NAMRA_AUDITOR",
      taxpayerId: null,
      organisationId: null,
      capabilities: ["SELLER", "BUYER"],
      dynamicPermissions: ["custom-role:approve"],
    });
    expect(getUserAccess(actor)).toMatchObject({
      userId: "user-1",
      organisationId: null,
      taxpayerId: null,
      role: "NAMRA_AUDITOR",
      isNationalScope: true,
      isDevelopmentIdentity: false,
      capabilities: ["BUYER", "SELLER"],
    });
    expect(getUserAccess(actor).permissions).toContain("custom-role:approve");
    expect(getUserAccess(actor).permissions).toContain("audit:read");
  });

  it("is a pure function of its input — never a cached snapshot", () => {
    const actor = user({ dynamicPermissions: ["custom-role:approve"] });
    const first = getUserAccess(actor);
    actor.dynamicPermissions.push("another:permission");
    const second = getUserAccess(actor);
    expect(second.permissions).toContain("another:permission");
    expect(first.permissions).not.toContain("another:permission");
  });
});
