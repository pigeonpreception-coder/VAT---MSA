import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { listExceptions } from "@/lib/data/repository";
import { formatDateTime, formatMoney } from "@/lib/format";
import { getCurrentUser, requirePermission } from "@/lib/auth";

export const metadata: Metadata = { title: "Reconciliation" };
export const dynamic = "force-dynamic";

export default async function ReconciliationPage() {
  const user = await getCurrentUser();
  requirePermission(user, "exceptions:read");
  const exceptions = await listExceptions(user);
  const critical = exceptions.filter((item) => item.severity === "CRITICAL").length;
  return <AppShell active="reconciliation" permission="exceptions:read">
    <PageHeader eyebrow="Two-sided VAT control" title="Reconciliation and exceptions" description="Every discrepancy becomes a managed exception linked to the underlying invoice, VAT transaction and taxpayer evidence." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Open exceptions</span><span className="metric-icon">!</span></div><div className="metric-value">{exceptions.filter((item) => item.status === "OPEN").length}</div><div className="metric-foot">Awaiting controlled review</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Critical severity</span><span className="metric-icon">C</span></div><div className="metric-value">{critical}</div><div className="metric-foot warning">Prioritised for officer attention</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Exception value</span><span className="metric-icon">N$</span></div><div className="metric-value">{formatMoney(exceptions.reduce((sum, item) => sum + Number(item.total_cents), 0))}</div><div className="metric-foot">Gross value under exception control</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Resolution policy</span><span className="metric-icon">2</span></div><div className="metric-value">Dual</div><div className="metric-foot">High-impact closure requires approval</div></article>
    </section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Exception work queue</h2><div className="panel-meta">Risk and matching differences retained as auditable workflow items</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Exception</th><th>Invoice</th><th>Supplier</th><th>Type</th><th>Severity</th><th>Status</th><th>Opened</th><th>Reason</th></tr></thead>
      <tbody>{exceptions.map((item) => <tr key={String(item.id)}>
        <td className="mono">{String(item.id)}</td><td><Link href={`/invoices/${item.invoice_id}`}><strong>{String(item.invoice_number)}</strong></Link><div className="amount">{formatMoney(Number(item.total_cents), String(item.currency))}</div></td>
        <td>{String(item.supplier_name)}</td><td>{String(item.exception_type).replaceAll("_", " ")}</td><td><StatusBadge value={String(item.severity)} /></td><td><StatusBadge value={String(item.status)} /></td><td>{formatDateTime(String(item.created_at))}</td><td style={{ minWidth: 260 }}>{String(item.summary)}</td>
      </tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
