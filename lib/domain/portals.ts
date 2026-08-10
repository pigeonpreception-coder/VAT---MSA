export type PortalKey = "buyer" | "seller" | "namra" | "namra-admin" | "super-admin" | "developer";

export type PortalDefinition = {
  key: PortalKey;
  name: string;
  audience: string;
  description: string;
  href: string;
  capability?: "BUYER" | "SELLER";
  roles: readonly string[];
};

const TAXPAYER_ROLES = ["TAXPAYER_OWNER", "TAXPAYER_ADMIN", "TAXPAYER_ACCOUNTANT", "TAXPAYER_STAFF", "TAXPAYER_VIEWER"];

export const PORTALS: readonly PortalDefinition[] = [
  { key: "buyer", name: "Buyer", audience: "Procurement and finance", description: "Purchases, input VAT, expenses, evidence and returns.", href: "/portal/buyer", capability: "BUYER", roles: [...TAXPAYER_ROLES, "BUYER_ADMIN", "BUYER_USER", "PILOT_ADMIN"] },
  { key: "seller", name: "Seller", audience: "Sales and finance", description: "Quotations, sales, output VAT, inventory, projects and returns.", href: "/portal/seller", capability: "SELLER", roles: [...TAXPAYER_ROLES, "SELLER_ADMIN", "SELLER_OPERATOR", "SELLER_VIEWER", "PILOT_ADMIN"] },
  { key: "namra", name: "NamRA", audience: "Compliance, audit and refunds", description: "National work queues, taxpayer timelines, evidence and controlled decisions.", href: "/portal/namra", roles: ["NAMRA_COMPLIANCE_OFFICER", "NAMRA_AUDITOR", "NAMRA_REFUND_OFFICER", "NAMRA_SUPERVISOR", "PILOT_ADMIN"] },
  { key: "namra-admin", name: "NamRA Administration", audience: "Access administrators", description: "Identity, taxpayer activation, roles, memberships and provider posture.", href: "/portal/namra-admin", roles: ["NAMRA_SYSTEM_ADMIN", "PILOT_ADMIN"] },
  { key: "super-admin", name: "Super Administration", audience: "Platform, SRE and security", description: "Technical health, integrations, eventing and security configuration without tax-data inheritance.", href: "/portal/super-admin", roles: ["SUPER_ADMIN", "INFRASTRUCTURE_ADMIN", "SECURITY_ANALYST", "PILOT_ADMIN"] },
  { key: "developer", name: "Developer and sandbox", audience: "Approved SaaS and ERP teams", description: "API clients, contracts, webhooks, quotas and conformance posture.", href: "/portal/developer", roles: ["TAXPAYER_OWNER", "TAXPAYER_ADMIN", "SELLER_ADMIN", "DEVELOPER_PARTNER", "PILOT_ADMIN"] },
] as const;

export function portalRoleAllows(key: PortalKey, role: string, capabilities: ReadonlySet<string>) {
  const portal = PORTALS.find((item) => item.key === key);
  return Boolean(portal?.roles.includes(role) && (!portal.capability || capabilities.has(portal.capability)));
}
