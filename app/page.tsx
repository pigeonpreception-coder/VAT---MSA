import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getDashboardSnapshot } from "@/lib/data/repository";
import { formatDateTime, formatMoney } from "@/lib/format";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Operations dashboard" };
export const dynamic = "force-dynamic";

function activityLabel(action: string): string {
  return action.split("_").map((part) => part[0] + part.slice(1).toLowerCase()).join(" ");
}

export default async function DashboardPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "dashboard:read", { operationClass: "READ" });
  const snapshot = await getDashboardSnapshot(user);
  const highRisk = snapshot.riskCounts.filter((item) => item.risk_level === "HIGH" || item.risk_level === "CRITICAL").reduce((sum, item) => sum + item.count, 0);

  return (
    <AppShell active="dashboard" permission="dashboard:read">
      <PageHeader
        eyebrow="National operations"
        title="VAT transaction control centre"
        description="Live certification, ledger, reconciliation and compliance position for the controlled pilot."
        actions={<Link className="btn btn-primary" href="/invoices/new">+ Submit invoice</Link>}
      />

      <section className="metric-grid" aria-label="Key VAT metrics">
        <article className="metric"><div className="metric-top"><span className="metric-label">Certified documents</span><span className="metric-icon">#</span></div><div className="metric-value">{snapshot.metrics.invoice_count}</div><div className="metric-foot positive">All records committed atomically</div></article>
        <article className="metric"><div className="metric-top"><span className="metric-label">Transaction value</span><span className="metric-icon">N$</span></div><div className="metric-value">{formatMoney(snapshot.metrics.total_cents)}</div><div className="metric-foot">Gross fiscal value in the pilot ledger</div></article>
        <article className="metric"><div className="metric-top"><span className="metric-label">VAT controlled</span><span className="metric-icon">15</span></div><div className="metric-value">{formatMoney(snapshot.metrics.tax_cents)}</div><div className="metric-foot">Output VAT represented by certificates</div></article>
        <article className="metric"><div className="metric-top"><span className="metric-label">Open exceptions</span><span className="metric-icon">!</span></div><div className="metric-value">{snapshot.metrics.exception_count}</div><div className={`metric-foot ${highRisk ? "warning" : "positive"}`}>{highRisk} high or critical risk item{highRisk === 1 ? "" : "s"}</div></article>
      </section>

      <div className="grid-2">
        <section className="panel">
          <div className="panel-head"><div><h2 className="panel-title">Recent fiscal documents</h2><div className="panel-meta">Latest certified invoice activity</div></div><Link className="btn btn-secondary" href="/invoices">View all</Link></div>
          <div className="table-wrap"><table>
            <thead><tr><th>Invoice</th><th>Supplier</th><th>Customer</th><th>VAT</th><th>Status</th><th>Risk</th></tr></thead>
            <tbody>{snapshot.recentInvoices.map((invoice) => (
              <tr key={invoice.id}>
                <td><Link href={`/invoices/${invoice.id}`}><strong>{invoice.invoiceNumber}</strong></Link><div className="muted mono">{invoice.id}</div></td>
                <td>{invoice.supplierName}<div className="muted">{invoice.supplierVatNumber}</div></td>
                <td>{invoice.customerName}</td>
                <td className="amount">{formatMoney(invoice.taxCents, invoice.currency)}</td>
                <td><StatusBadge value={invoice.status} /></td><td><StatusBadge value={invoice.riskLevel} /></td>
              </tr>
            ))}</tbody>
          </table></div>
        </section>

        <aside className="panel">
          <div className="panel-head"><div><h2 className="panel-title">Evidence stream</h2><div className="panel-meta">Append-only operational audit trail</div></div></div>
          <div className="panel-body activity">
            {snapshot.recentAudit.map((event) => (
              <div className="activity-item" key={event.id}>
                <span className="activity-marker" aria-hidden="true" />
                <div><strong>{activityLabel(event.action)}</strong><p>{event.resource_type} · <span className="mono">{event.resource_id}</span></p></div>
                <time className="activity-time" dateTime={event.occurred_at}>{formatDateTime(event.occurred_at)}</time>
              </div>
            ))}
          </div>
        </aside>
      </div>
    </AppShell>
  );
}
