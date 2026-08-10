import { describe, expect, it } from "vitest";
import { portalRoleAllows } from "@/lib/domain/portals";

describe("portal separation policy", () => {
  it("requires the active Buyer capability for taxpayer buyer access", () => {
    expect(portalRoleAllows("buyer", "TAXPAYER_OWNER", new Set(["BUYER"]))).toBe(true);
    expect(portalRoleAllows("buyer", "TAXPAYER_OWNER", new Set(["SELLER"]))).toBe(false);
  });

  it("keeps taxpayer roles out of the NamRA portal", () => {
    expect(portalRoleAllows("namra", "TAXPAYER_ADMIN", new Set(["BUYER", "SELLER"]))).toBe(false);
    expect(portalRoleAllows("namra", "NAMRA_AUDITOR", new Set())).toBe(true);
  });

  it("does not grant Super Administration through a financial role", () => {
    expect(portalRoleAllows("super-admin", "TAXPAYER_OWNER", new Set(["BUYER", "SELLER"]))).toBe(false);
    expect(portalRoleAllows("super-admin", "SUPER_ADMIN", new Set())).toBe(true);
  });

  it("allows the local pilot administrator to evaluate every separated portal", () => {
    for (const portal of ["buyer", "seller", "namra", "namra-admin", "super-admin", "developer"] as const) {
      expect(portalRoleAllows(portal, "PILOT_ADMIN", new Set(["BUYER", "SELLER"]))).toBe(true);
    }
  });
});
