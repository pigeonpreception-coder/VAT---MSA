import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getDeveloperPortalSnapshot } from "@/lib/data/platform-repository";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "Developer portal" };
export const dynamic = "force-dynamic";

export default async function DeveloperPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "developer");
  const data = await getDeveloperPortalSnapshot(user);
  return <PortalShell portalKey="developer" user={user}>
    <PageHeader eyebrow="Developer and sandbox" title="Applications, contracts, webhooks and conformance posture" description="Production tax data is not a developer-portal concern. Machine credentials remain external, client scopes are explicit, webhook subscriptions are signed, and production approval remains disabled until conformance evidence exists." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Applications</span><span className="metric-icon">A</span></div><div className="metric-value">{data.clients.length}</div><div className="metric-foot">Tenant-scoped client registrations</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Active credentials</span><span className="metric-icon">K</span></div><div className="metric-value">{data.clients.filter((item) => item.status === "ACTIVE").length}</div><div className="metric-foot">Secret values never displayed</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Webhooks</span><span className="metric-icon">W</span></div><div className="metric-value">{data.webhooks.length}</div><div className="metric-foot">Signed endpoint contracts</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Conformance</span><span className="metric-icon">C</span></div><div className="metric-value">Pending</div><div className="metric-foot warning">Sandbox certification not configured</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Application registry</h2><div className="panel-meta">Scopes, lifecycle and rate profile remain inspectable</div></div></div>{data.clients.length ? <div className="table-wrap"><table><thead><tr><th>Application</th><th>Client key</th><th>Scopes</th><th>Rate profile</th><th>Status</th></tr></thead><tbody>{data.clients.map((item) => <tr key={String(item.id)}><td><strong>{String(item.name)}</strong></td><td className="mono">{String(item.client_key)}</td><td className="mono">{String(item.scopes)}</td><td>{String(item.rate_limit_profile)}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div> : <div className="empty"><strong>No applications in scope</strong>An authorised organisation administrator must create the client registration before credentials can be provisioned.</div>}</section>
  </PortalShell>;
}
