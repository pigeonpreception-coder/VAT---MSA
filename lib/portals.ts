import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import { PORTALS, portalRoleAllows, type PortalKey } from "@/lib/domain/portals";
import type { UserContext } from "@/lib/domain/types";

export { PORTALS, portalRoleAllows, type PortalDefinition, type PortalKey } from "@/lib/domain/portals";

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
  return PORTALS.filter((portal) => portalRoleAllows(portal.key, user.role, capabilities));
}

export async function requirePortalAccess(user: UserContext, key: PortalKey) {
  const portal = (await getAvailablePortals(user)).find((item) => item.key === key);
  if (!portal) throw new AccessDeniedError(`Role ${user.role} is not authorised for the ${key} portal in the active organisation context.`);
  return portal;
}
