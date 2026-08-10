import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getTechnicalPlatformSnapshot } from "@/lib/data/platform-repository";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "Super administration portal" };
export const dynamic = "force-dynamic";

export default async function SuperAdminPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "super-admin");
  const data = await getTechnicalPlatformSnapshot();
  const pendingEvents = Number(data.outbox.find((item) => item.status === "PENDING")?.count ?? 0);
  const criticalSecurity = Number(data.securityEvents.find((item) => item.severity === "CRITICAL")?.count ?? 0);
  return <PortalShell portalKey="super-admin" user={user}>
    <PageHeader eyebrow="Super administration" title="Technical health, security and integration configuration" description="This projection uses a technical read model. It excludes invoices, return values, documents, refunds, taxpayer identifiers and internal tax-risk records by default." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Components</span><span className="metric-icon">C</span></div><div className="metric-value">{data.components.length}</div><div className="metric-foot">Capability-specific readiness</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Disabled integrations</span><span className="metric-icon">I</span></div><div className="metric-value">{data.integrations.filter((item) => item.operational_status === "DISABLED").length}</div><div className="metric-foot warning">Contracts or credentials required</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Pending events</span><span className="metric-icon">E</span></div><div className="metric-value">{pendingEvents}</div><div className="metric-foot">Durable outbox backlog</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Critical security events</span><span className="metric-icon">S</span></div><div className="metric-value">{criticalSecurity}</div><div className="metric-foot">Technical signal count</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Service component posture</h2><div className="panel-meta">A dependency block cannot be hidden by overall availability</div></div></div><div className="table-wrap"><table><thead><tr><th>Component</th><th>Type</th><th>Criticality</th><th>Configuration</th><th>Operations</th><th>Dependency</th></tr></thead><tbody>{data.components.map((item) => <tr key={String(item.id)}><td><strong>{String(item.display_name)}</strong></td><td>{String(item.component_type)}</td><td><StatusBadge value={String(item.criticality)} /></td><td><StatusBadge value={String(item.configuration_status)} /></td><td><StatusBadge value={String(item.operational_status)} /></td><td>{String(item.dependency_summary)}</td></tr>)}</tbody></table></div></section>
  </PortalShell>;
}
