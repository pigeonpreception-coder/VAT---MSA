import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { formatMoney } from "@/lib/format";
import { QuotationActions } from "./QuotationActions";
import { QuotationForm } from "./QuotationForm";

export const metadata: Metadata = { title: "Sales and quotations" };
export const dynamic = "force-dynamic";

export default async function CommercialPage() {
  const user = await getCurrentUser();
  requirePermission(user, "commercial:read");
  const snapshot = await getBusinessPlatformSnapshot(user);
  return <AppShell active="commercial" permission="commercial:read">
    <PageHeader eyebrow="Commercial domain" title="Parties, products and quotations" description="Tenant-scoped commercial records feed invoicing without bypassing fiscal certification. Quotation totals and VAT are calculated from immutable integer inputs." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Business parties</span><span className="metric-icon">P</span></div><div className="metric-value">{Number(snapshot.metrics.parties ?? 0)}</div><div className="metric-foot">Customer and supplier relationships</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Quotations</span><span className="metric-icon">Q</span></div><div className="metric-value">{Number(snapshot.metrics.quotations ?? 0)}</div><div className="metric-foot">Versioned commercial offers</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Quoted value</span><span className="metric-icon">N$</span></div><div className="metric-value">{formatMoney(Number(snapshot.metrics.quoted_value_cents ?? 0))}</div><div className="metric-foot">Issued, accepted and converted</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Catalog products</span><span className="metric-icon">#</span></div><div className="metric-value">{snapshot.products.length}</div><div className="metric-foot">Controlled tax categories and rates</div></article>
    </section>
    <div className="grid-2">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Quotation register</h2><div className="panel-meta">{snapshot.organisation.legal_name}</div></div></div><div className="table-wrap"><table><thead><tr><th>Quotation</th><th>Customer</th><th>Issue / validity</th><th>Net</th><th>VAT</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>{snapshot.quotations.map((item) => <tr key={String(item.id)}><td><strong>{String(item.quotation_number)}</strong><div className="mono muted">{String(item.id)}</div></td><td>{String(item.customer_name)}</td><td>{String(item.issue_date)}<div className="muted">to {String(item.valid_until)}</div></td><td className="amount">{formatMoney(Number(item.subtotal_cents), String(item.currency))}</td><td className="amount">{formatMoney(Number(item.tax_cents), String(item.currency))}</td><td className="amount">{formatMoney(Number(item.total_cents), String(item.currency))}</td><td><StatusBadge value={String(item.status)} /></td><td><QuotationActions id={String(item.id)} organisationId={snapshot.organisation.id} status={String(item.status)} /></td></tr>)}</tbody></table></div></section>
      <aside className="panel"><div className="panel-head"><div><h2 className="panel-title">Issue quotation</h2><div className="panel-meta">Standard Namibia pilot VAT rate: 15%</div></div></div><div className="panel-body"><QuotationForm organisationId={snapshot.organisation.id} parties={snapshot.parties.filter((item) => String(item.relationships ?? "").includes("CUSTOMER")).map((item) => ({ id: String(item.id), label: String(item.display_name) }))} products={snapshot.products.map((item) => ({ id: String(item.id), label: `${String(item.sku)} — ${String(item.name)}` }))} /></div></aside>
    </div>
  </AppShell>;
}
