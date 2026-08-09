import Link from "next/link";
import { getCurrentUser, hasPermission, requirePermission } from "@/lib/auth";
import { initials } from "@/lib/format";

const navigation = [
  { href: "/", label: "Operations dashboard", key: "dashboard", permission: "dashboard:read" },
  { href: "/invoices", label: "Tax invoices", key: "invoices", permission: "invoices:read" },
  { href: "/taxpayers", label: "Taxpayer registry", key: "taxpayers", permission: "taxpayers:read" },
  { href: "/organisations", label: "Identity foundation", key: "organisations", permission: "identity:read" },
  { href: "/registrations", label: "Registration intake", key: "registrations", permission: "registrations:read" },
  { href: "/commercial", label: "Sales & quotations", key: "commercial", permission: "commercial:read" },
  { href: "/accounting", label: "Accounting", key: "accounting", permission: "accounting:read" },
  { href: "/operations", label: "Business operations", key: "operations", permission: "expenses:read" },
  { href: "/reconciliation", label: "Reconciliation", key: "reconciliation", permission: "exceptions:read" },
  { href: "/returns", label: "VAT returns", key: "returns", permission: "returns:read" },
  { href: "/audit", label: "Audit evidence", key: "audit", permission: "audit:read" },
  { href: "/security", label: "Security operations", key: "security", permission: "security:read" },
];

export async function AppShell({ active, permission, children }: { active: string; permission: string; children: React.ReactNode }) {
  const user = await getCurrentUser();
  requirePermission(user, permission);

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark" aria-hidden="true">V</div>
          <div><strong>VAT-MSA</strong><small>Fiscal transaction platform</small></div>
        </div>
        <nav className="nav" aria-label="Primary navigation">
          <div className="nav-label">Workspace</div>
          {navigation.filter((item) => hasPermission(user, item.permission)).map((item) => (
            <Link key={item.key} className={`nav-link ${active === item.key ? "active" : ""}`} href={item.href} aria-current={active === item.key ? "page" : undefined}>
              <span className="nav-dot" aria-hidden="true" />{item.label}
            </Link>
          ))}
          <div className="nav-label">Submission</div>
          {hasPermission(user, "invoices:submit") ? <Link className={`nav-link ${active === "new-invoice" ? "active" : ""}`} href="/invoices/new">
            <span className="nav-dot" aria-hidden="true" />Submit invoice
          </Link> : null}
        </nav>
        <div className="sidebar-foot">
          Namibia pilot environment<br />Rule set: NA-VAT-PILOT-2026.1
        </div>
      </aside>
      <main className="main">
        <header className="topbar">
          <div className="env-pill"><span className="pulse" /> Controlled pilot</div>
          <div className="user-block">
            <div><strong>{user.displayName}</strong><span>{user.role.replaceAll("_", " ")}{user.isDevelopmentIdentity ? " · local identity" : ""}</span></div>
            <div className="avatar" aria-hidden="true">{initials(user.displayName)}</div>
          </div>
        </header>
        <div className="content">{children}</div>
      </main>
    </div>
  );
}
