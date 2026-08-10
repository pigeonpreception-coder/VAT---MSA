import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getIdentityFoundationSnapshot } from "@/lib/data/identity-repository";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "NamRA administration portal" };
export const dynamic = "force-dynamic";

export default async function NamraAdminPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "namra-admin");
  const identity = await getIdentityFoundationSnapshot(user);
  return <PortalShell portalKey="namra-admin" user={user}>
    <PageHeader eyebrow="NamRA administration" title="Identity, taxpayer activation and access governance" description="Administrative authority does not inherit transaction, return, refund or internal-risk access. Sensitive access changes require JIT authority, MFA, approval and immutable audit in production." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Active users</span><span className="metric-icon">U</span></div><div className="metric-value">{Number(identity.access.active_users ?? 0)}</div><div className="metric-foot">Provisioned platform identities</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Identity links</span><span className="metric-icon">L</span></div><div className="metric-value">{Number(identity.access.active_identity_links ?? 0)}</div><div className="metric-foot">External subjects mapped internally</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Memberships</span><span className="metric-icon">M</span></div><div className="metric-value">{Number(identity.access.active_memberships ?? 0)}</div><div className="metric-foot">Organisation-scoped access</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Pending registration</span><span className="metric-icon">R</span></div><div className="metric-value">{identity.registrations.filter((item) => item.status !== "APPROVED" && item.status !== "REJECTED").length}</div><div className="metric-foot warning">Authoritative verification required</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Identity provider boundaries</h2><div className="panel-meta">Authority and configuration status are separate</div></div></div><div className="table-wrap"><table><thead><tr><th>Provider</th><th>Type</th><th>Authority</th><th>Status</th><th>Configuration</th></tr></thead><tbody>{identity.providers.map((item) => <tr key={String(item.provider_key)}><td><strong>{String(item.display_name)}</strong><div className="mono muted">{String(item.provider_key)}</div></td><td>{String(item.provider_type)}</td><td>{String(item.authority_level)}</td><td><StatusBadge value={String(item.status)} /></td><td><StatusBadge value={String(item.configuration_status)} /></td></tr>)}</tbody></table></div></section>
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>No implicit financial access.</strong><br />This administrative projection intentionally contains identity and access metadata only. Taxpayer transaction access requires a separately assigned NamRA operational role and purpose.</div>
  </PortalShell>;
}
