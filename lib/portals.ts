import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import { PORTALS, portalRoleAllows, type PortalKey } from "@/lib/domain/portals";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import type { UserContext } from "@/lib/domain/types";

export { PORTALS, portalRoleAllows, type PortalDefinition, type PortalKey } from "@/lib/domain/portals";

const PORTAL_PERMISSIONS: Record<PortalKey, string> = {
  buyer: "dashboard:read",
  seller: "dashboard:read",
  namra: "dashboard:read",
  "namra-admin": "identity:read",
  "super-admin": "platform:read",
  developer: "developer:read",
};

async function capabilitySet(user: UserContext) {
  if (user.role === "PILOT_ADMIN") return new Set(["BUYER", "SELLER"]);
  if (!user.taxpayerId) return new Set<string>();
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT c.capability FROM organisation_capabilities c
    JOIN organisations o ON o.id=c.organisation_id
    WHERE o.taxpayer_id=? AND o.status='ACTIVE' AND c.status='ACTIVE'
      AND datetime(c.effective_from)<=CURRENT_TIMESTAMP
      AND (c.effective_to IS NULL OR datetime(c.effective_to)>CURRENT_TIMESTAMP)`).bind(user.taxpayerId).all<{ capability: string }>();
  return new Set(result.results.map((item) => item.capability));
}

export async function getAvailablePortals(user: UserContext) {
  const capabilities = await capabilitySet(user);
  const candidates = PORTALS.filter((portal) => portalRoleAllows(portal.key, user.role, capabilities));
  const evaluated = await Promise.all(candidates.map(async (portal) => {
    try {
      await requireLicensedPermission(user, PORTAL_PERMISSIONS[portal.key], { operationClass: "READ" });
      return portal;
    } catch (error) {
      if (error instanceof AccessDeniedError) return null;
      throw error;
    }
  }));
  return evaluated.filter((portal): portal is NonNullable<typeof portal> => portal !== null);
}

export async function requirePortalAccess(user: UserContext, key: PortalKey) {
  const portal = (await getAvailablePortals(user)).find((item) => item.key === key);
  if (!portal) throw new AccessDeniedError(`Role ${user.role} is not authorised for the ${key} portal in the active organisation context.`);
  const decision = await requireLicensedPermission(user, PORTAL_PERMISSIONS[key], { operationClass: "READ" });
  return { ...portal, licenseState: decision.license?.state ?? (decision.authorityDomain === "GOVERNMENT_TAX" ? "TAX_AUTHORIZED" : "NOT_APPLICABLE") };
}
