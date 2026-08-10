import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getComplianceSnapshot } from "@/lib/data/compliance-repository";
import { getIdentityFoundationSnapshot } from "@/lib/data/identity-repository";
import { getVatLifecycleSnapshot } from "@/lib/data/vat-lifecycle-repository";
import { formatDateTime } from "@/lib/format";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "NamRA portal" };
export const dynamic = "force-dynamic";

export default async function NamraPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "namra");
  const [identity, compliance, vat] = await Promise.all([getIdentityFoundationSnapshot(user), getComplianceSnapshot(user), getVatLifecycleSnapshot(user)]);
  return <PortalShell portalKey="namra" user={user}>
    <PageHeader eyebrow="NamRA officer workspace" title="Due, abnormal, unresolved and assigned work" description="National tax data and internal indicators appear only for authorised NamRA roles. Risk indicators remain advisory and require human evidence-led review before any adverse action." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Taxpayers</span><span className="metric-icon">T</span></div><div className="metric-value">{identity.organisations.length}</div><div className="metric-foot">Canonical active organisations</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Open cases</span><span className="metric-icon">C</span></div><div className="metric-value">{compliance.cases.filter((item) => item.status !== "CLOSED").length}</div><div className="metric-foot">Evidence-led work queue</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Risk indicators</span><span className="metric-icon">R</span></div><div className="metric-value">{compliance.risks.filter((item) => item.status === "OPEN").length}</div><div className="metric-foot warning">No automated adverse decision</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Return approvals</span><span className="metric-icon">A</span></div><div className="metric-value">{vat.approvals.filter((item) => item.status === "PENDING").length}</div><div className="metric-foot">Maker-checker tasks</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Officer case queue</h2><div className="panel-meta">Purpose, assignment and classification controls apply on every action</div></div></div><div className="table-wrap"><table><thead><tr><th>Case</th><th>Taxpayer</th><th>Type</th><th>Risk tier</th><th>Status</th><th>Updated</th></tr></thead><tbody>{compliance.cases.map((item) => <tr key={String(item.id)}><td><strong>{String(item.case_number)}</strong><div className="muted">{String(item.title)}</div></td><td>{String(item.legal_name ?? item.taxpayer_id)}</td><td>{String(item.case_type).replaceAll("_", " ")}</td><td><StatusBadge value={String(item.risk_tier)} /></td><td><StatusBadge value={String(item.status)} /></td><td>{formatDateTime(String(item.updated_at))}</td></tr>)}</tbody></table></div></section>
  </PortalShell>;
}
