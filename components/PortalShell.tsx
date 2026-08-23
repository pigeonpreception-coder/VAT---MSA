import Link from "next/link";
import { hasPermission } from "@/lib/auth";
import type { UserContext } from "@/lib/domain/types";
import { getAvailablePortals, requirePortalAccess, type PortalKey } from "@/lib/portals";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { initials } from "@/lib/format";

const PORTAL_NAVIGATION: Record<PortalKey, Array<{ href: string; label: string; permission?: string }>> = {
  buyer: [
    { href: "/portal/buyer", label: "Buyer overview" }, { href: "/invoices", label: "Supplier invoices", permission: "invoices:read" }, { href: "/operations", label: "Purchases and expenses", permission: "expenses:read" }, { href: "/reconciliation", label: "Input VAT reconciliation", permission: "exceptions:read" }, { href: "/returns", label: "VAT returns", permission: "returns:read" }, { href: "/documents", label: "Evidence documents", permission: "documents:read" }, { href: "/reports", label: "Reports", permission: "reports:read" },
  ],
  seller: [
    { href: "/portal/seller", label: "Seller overview" }, { href: "/commercial", label: "Customers and quotations", permission: "commercial:read" }, { href: "/invoices", label: "Sales invoices", permission: "invoices:read" }, { href: "/accounting", label: "Accounting", permission: "accounting:read" }, { href: "/operations", label: "Inventory and projects", permission: "inventory:read" }, { href: "/returns", label: "VAT returns", permission: "returns:read" }, { href: "/integrations", label: "Integrations", permission: "integrations:read" },
  ],
  namra: [
    { href: "/portal/namra", label: "Officer work queue" }, { href: "/taxpayers", label: "Taxpayers", permission: "taxpayers:read" }, { href: "/registrations", label: "Registrations", permission: "registrations:read" }, { href: "/invoices", label: "Transactions", permission: "invoices:read" }, { href: "/returns", label: "Returns", permission: "returns:read" }, { href: "/reconciliation", label: "Reconciliation", permission: "exceptions:read" }, { href: "/compliance", label: "Compliance", permission: "compliance:read" }, { href: "/cases", label: "Audit and risk cases", permission: "cases:manage" }, { href: "/refunds", label: "Refund review", permission: "refunds:read" },
  ],
  "namra-admin": [
    { href: "/portal/namra-admin", label: "Administration overview" }, { href: "/organisations", label: "Identity and organisations", permission: "identity:read" }, { href: "/registrations", label: "Taxpayer activation", permission: "registrations:read" },
  ],
  "super-admin": [
    { href: "/portal/super-admin", label: "Platform overview" }, { href: "/integrations", label: "Integration posture", permission: "integrations:read" }, { href: "/security", label: "Security operations", permission: "security:read" },
  ],
  developer: [
    { href: "/portal/developer", label: "Developer overview" }, { href: "/developer", label: "Clients and webhooks", permission: "developer:read" }, { href: "/integrations", label: "Connection posture", permission: "integrations:read" },
  ],
};

export async function PortalShell({ portalKey, user, children }: { portalKey: PortalKey; user: UserContext; children: React.ReactNode }) {
  const portal = await requirePortalAccess(user, portalKey);
  const available = await getAvailablePortals(user);
  const navigation = (await Promise.all(PORTAL_NAVIGATION[portalKey].map(async (item) => {
    if (!item.permission || !hasPermission(user, item.permission)) return item.permission ? null : item;
    try {
      await requireLicensedPermission(user, item.permission, { operationClass: "READ" });
      return item;
    } catch {
      return null;
    }
  }))).filter((item): item is NonNullable<typeof item> => item !== null);

  return <div className="app-shell">
    <aside className="sidebar">
      <div className="brand"><div className="brand-mark" aria-hidden="true">V</div><div><strong>VAT-MSA</strong><small>{portal.name} portal</small></div></div>
      <nav className="nav" aria-label={`${portal.name} navigation`}>
        <div className="nav-label">{portal.audience}</div>
        {navigation.map((item, index) => <Link key={item.href} className={`nav-link ${index === 0 ? "active" : ""}`} href={item.href}><span className="nav-dot" aria-hidden="true" />{item.label}</Link>)}
        <div className="nav-label">Switch workspace</div>
        <Link className="nav-link" href="/portals"><span className="nav-dot" aria-hidden="true" />All available portals</Link>
        {available.filter((item) => item.key !== portalKey).map((item) => <Link key={item.key} className="nav-link" href={item.href}><span className="nav-dot" aria-hidden="true" />{item.name}</Link>)}
      </nav>
      <div className="sidebar-foot">Portal boundary: {portal.key}<br />Authorisation is re-evaluated server-side</div>
    </aside>
    <main className="main">
      <header className="topbar"><div className="env-pill"><span className="pulse" /> Controlled pilot - {portal.name}</div><div className="user-block"><div><strong>{user.displayName}</strong><span>{user.role.replaceAll("_", " ")}{user.isDevelopmentIdentity ? " - local identity" : ""}</span></div><div className="avatar" aria-hidden="true">{initials(user.displayName)}</div></div></header>
      <div className="content">{["SUSPENDED", "EXPIRED", "CANCELLED"].includes(portal.licenseState) ? <div className="notice notice-warning" role="status"><strong>Licence continuity mode</strong><br />Historical reads, exports and statutory compliance remain available. New business and privileged administration actions are disabled; records are preserved.</div> : null}{user.role === "PILOT_ADMIN" ? <div className="alert alert-info portal-pilot-alert"><strong>Combined pilot identity</strong><br />Production identities receive only their assigned portal and scope. This local identity combines roles so the separated experiences can be evaluated.</div> : null}{children}</div>
    </main>
  </div>;
}
