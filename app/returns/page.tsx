import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getVatLifecycleSnapshot } from "@/lib/data/vat-lifecycle-repository";
import { formatDateTime, formatMoney } from "@/lib/format";
import { ReturnActions } from "./ReturnActions";

export const metadata: Metadata = { title: "VAT returns" };
export const dynamic = "force-dynamic";

export default async function ReturnsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "returns:read");
  const snapshot = await getVatLifecycleSnapshot(user);
  const output = snapshot.periods.reduce((sum, item) => sum + Number(item.output_tax_cents ?? 0), 0);
  const input = snapshot.periods.reduce((sum, item) => sum + Number(item.input_tax_cents ?? 0), 0);
  const pending = snapshot.approvals.filter((item) => item.status === "PENDING").length;
  return <AppShell active="returns" permission="returns:read">
    <PageHeader eyebrow="Governed VAT lifecycle" title="Period close, return versions and filing control" description="Ledger evidence produces reproducible return versions. Maker-checker approval locks the period; only an acknowledged statutory-provider response may mark a return filed." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Output VAT</span><span className="metric-icon">O</span></div><div className="metric-value">{formatMoney(output)}</div><div className="metric-foot">Certificate-backed seller liability</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Eligible input</span><span className="metric-icon">I</span></div><div className="metric-value">{formatMoney(input)}</div><div className="metric-foot">Matched buyer evidence only</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Net position</span><span className="metric-icon">Σ</span></div><div className="metric-value">{formatMoney(output - input)}</div><div className="metric-foot">Across latest controlled versions</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Approval queue</span><span className="metric-icon">M/C</span></div><div className="metric-value">{pending}</div><div className="metric-foot warning">Independent maker-checker decisions</div></article>
    </section>
    {!snapshot.provider.configured ? <div className="alert alert-info" style={{ marginBottom: 20 }}><strong>ITAS return submission is safely disabled.</strong><br />The integration is in {snapshot.provider.state.replaceAll("_", " ").toLowerCase()} state. An approved VAT-MSA return is not represented as legally filed until the statutory contract is configured and ITAS acknowledges it.</div> : null}
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">VAT period register</h2><div className="panel-meta">Versioned calculations with locked-period control</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Taxpayer</th><th>Period / due</th><th>Evidence</th><th>Output VAT</th><th>Input VAT</th><th>Net payable / (refund)</th><th>Period</th><th>Return</th><th>Action</th></tr></thead><tbody>{snapshot.periods.map((item) => <tr key={String(item.id)}><td><strong>{String(item.legal_name)}</strong><div className="mono muted">{String(item.vat_number)}</div></td><td>{String(item.period_code)}<div className="muted">Due {String(item.due_date)}</div></td><td>{Number(item.matched_count)} matched<div className={Number(item.unmatched_count) ? "warning" : "muted"}>{Number(item.unmatched_count)} blocked</div></td><td className="amount">{formatMoney(Number(item.output_tax_cents ?? 0))}</td><td className="amount">{formatMoney(Number(item.input_tax_cents ?? 0))}</td><td className="amount">{formatMoney(Number(item.net_payable_cents ?? 0))}</td><td><StatusBadge value={String(item.status)} /></td><td>{item.latest_return_id ? <Link href={`/returns/${String(item.latest_return_id)}`}><StatusBadge value={String(item.return_status)} /><div className="muted">Version {String(item.latest_version)}</div></Link> : <span className="muted">Not generated</span>}</td><td><ReturnActions periodId={String(item.id)} versionId={item.latest_return_id ? String(item.latest_return_id) : null} status={item.latest_return_id ? String(item.return_status) : String(item.status)} /></td></tr>)}</tbody></table></div>
    </section>
    <div className="grid-equal" style={{ marginTop: 20 }}>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Maker-checker queue</h2><div className="panel-meta">The requester cannot decide their own task</div></div></div><div className="table-wrap"><table><thead><tr><th>Resource</th><th>Action</th><th>Risk</th><th>Requested</th><th>Status</th><th>Decision</th></tr></thead><tbody>{snapshot.approvals.map((item) => <tr key={String(item.id)}><td><strong>{String(item.resource_type).replaceAll("_", " ")}</strong><div className="mono muted">{String(item.resource_id)}</div></td><td>{String(item.requested_action).replaceAll("_", " ")}</td><td><StatusBadge value={String(item.risk_tier)} /></td><td>{formatDateTime(String(item.requested_at))}</td><td><StatusBadge value={String(item.status)} /></td><td>{item.status === "PENDING" ? <ReturnActions approvalTaskId={String(item.id)} status="PENDING" /> : <span className="muted">{String(item.decision_comment ?? "Completed")}</span>}</td></tr>)}</tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Tax rule governance</h2><div className="panel-meta">Version is pinned into every generated return</div></div></div><div className="table-wrap"><table><thead><tr><th>Version</th><th>Effective</th><th>Rate</th><th>Authority reference</th><th>Status</th></tr></thead><tbody>{snapshot.rules.map((item) => <tr key={String(item.id)}><td><strong>{String(item.version)}</strong></td><td>{String(item.effective_from)}</td><td>{(Number(item.standard_rate_bps) / 100).toFixed(2)}%</td><td>{String(item.legal_authority_reference ?? "Awaiting NamRA confirmation")}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
    </div>
  </AppShell>;
}
