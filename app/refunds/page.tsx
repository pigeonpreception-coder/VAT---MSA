import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getComplianceSnapshot } from "@/lib/data/compliance-repository";
import { formatDateTime, formatMoney } from "@/lib/format";

export const metadata: Metadata = { title: "Refund control" };
export const dynamic = "force-dynamic";

export default async function RefundsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "refunds:read");
  const data = await getComplianceSnapshot(user);
  const total = data.refunds.reduce((sum, item) => sum + Number(item.amount_cents), 0);
  return <AppShell active="refunds" permission="refunds:read">
    <PageHeader eyebrow="Refund domain" title="Evidence, risk and payment authorisation" description="A negative draft return is not a payable refund. Eligibility, statutory filing acknowledgement, evidence review, risk review, supervisor approval and payment instruction remain separate controls." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Refund requests</span><span className="metric-icon">R</span></div><div className="metric-value">{data.refunds.length}</div><div className="metric-foot">Preliminary and controlled records</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Requested value</span><span className="metric-icon">N$</span></div><div className="metric-value">{formatMoney(total)}</div><div className="metric-foot">Not an approved payment amount</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Configuration blocks</span><span className="metric-icon">B</span></div><div className="metric-value">{data.refunds.filter((item) => String(item.status).startsWith("BLOCKED_")).length}</div><div className="metric-foot warning">No ITAS filing acknowledgement</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Approved for payment</span><span className="metric-icon">P</span></div><div className="metric-value">{data.refunds.filter((item) => item.status === "APPROVED_FOR_PAYMENT").length}</div><div className="metric-foot">Requires separate payment boundary</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Refund workflow register</h2><div className="panel-meta">No auto-payment and no invented bank status</div></div></div><div className="table-wrap"><table><thead><tr><th>Claim</th><th>Taxpayer</th><th>Period / version</th><th>Amount</th><th>Evidence</th><th>Risk</th><th>Status</th><th>Requested</th></tr></thead><tbody>{data.refunds.map((item) => <tr key={String(item.id)}><td><strong>{String(item.claim_number)}</strong><div className="mono muted">{String(item.id)}</div></td><td>{String(item.legal_name ?? item.taxpayer_id)}</td><td>{String(item.period_code)}<div className="muted">Version {String(item.version_number)}</div></td><td className="amount">{formatMoney(Number(item.amount_cents), String(item.currency))}</td><td><StatusBadge value={String(item.evidence_status)} /></td><td><StatusBadge value={String(item.risk_tier)} /></td><td><StatusBadge value={String(item.status)} /></td><td>{formatDateTime(String(item.requested_at))}</td></tr>)}</tbody></table></div></section>
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>Payment execution remains disabled by design.</strong><br />No banking or Treasury interface is configured. VAT-MSA can prepare a governed payment instruction only after the approved architecture&apos;s staged human controls and authoritative integration contract are satisfied.</div>
  </AppShell>;
}
