import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { getDashboardSnapshot } from "@/lib/data/repository";
import { getVatLifecycleSnapshot } from "@/lib/data/vat-lifecycle-repository";
import { formatMoney } from "@/lib/format";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "Seller portal" };
export const dynamic = "force-dynamic";

export default async function SellerPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "seller");
  const [business, dashboard, vat] = await Promise.all([getBusinessPlatformSnapshot(user), getDashboardSnapshot(user), getVatLifecycleSnapshot(user)]);
  const outputVat = vat.periods.reduce((sum, item) => sum + Number(item.output_tax_cents ?? 0), 0);
  return <PortalShell portalKey="seller" user={user}>
    <PageHeader eyebrow="Seller workspace" title="Sales, certification and output VAT position" description="The Seller experience prioritises customers, quotations, certified sales, inventory and return impact while retaining the same canonical organisation and fiscal records." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Invoices</span><span className="metric-icon">I</span></div><div className="metric-value">{Number(dashboard.metrics.invoice_count)}</div><div className="metric-foot">{formatMoney(Number(dashboard.metrics.total_cents))} gross value</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Output VAT</span><span className="metric-icon">V</span></div><div className="metric-value">{formatMoney(outputVat)}</div><div className="metric-foot">Across visible VAT periods</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Quotations</span><span className="metric-icon">Q</span></div><div className="metric-value">{business.quotations.length}</div><div className="metric-foot">{formatMoney(Number(business.metrics.quoted_value_cents ?? 0))} pipeline</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Exceptions</span><span className="metric-icon">!</span></div><div className="metric-value">{Number(dashboard.metrics.exception_count)}</div><div className="metric-foot warning">Requires controlled resolution</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Recent seller transaction activity</h2><div className="panel-meta">Certification state is explicit</div></div></div><div className="table-wrap"><table><thead><tr><th>Invoice</th><th>Supplier</th><th>Customer</th><th>Issue date</th><th>Tax</th><th>Total</th><th>Status</th></tr></thead><tbody>{dashboard.recentInvoices.map((item) => <tr key={item.id}><td><strong>{item.invoiceNumber}</strong></td><td>{item.supplierName}</td><td>{item.customerName}</td><td>{item.issueDate}</td><td className="amount">{formatMoney(item.taxCents, item.currency)}</td><td className="amount">{formatMoney(item.totalCents, item.currency)}</td><td><StatusBadge value={item.status} /></td></tr>)}</tbody></table></div></section>
  </PortalShell>;
}
