import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { getDocumentCustodySummary } from "@/lib/data/platform-repository";
import { getVatLifecycleSnapshot } from "@/lib/data/vat-lifecycle-repository";
import { formatMoney } from "@/lib/format";
import { requirePortalAccess } from "@/lib/portals";

export const metadata: Metadata = { title: "Buyer portal" };
export const dynamic = "force-dynamic";

export default async function BuyerPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "buyer");
  const [business, vat, documents] = await Promise.all([getBusinessPlatformSnapshot(user), getVatLifecycleSnapshot(user), getDocumentCustodySummary(user)]);
  const inputVat = vat.periods.reduce((sum, item) => sum + Number(item.input_tax_cents ?? 0), 0);
  const unmatched = vat.reconciliation.filter((item) => item.status !== "MATCHED").length;
  return <PortalShell portalKey="buyer" user={user}>
    <PageHeader eyebrow="Buyer workspace" title="Purchases, input VAT and evidence requiring action" description="This projection omits NamRA internal risk and technical administration. It shows authorised supplier transactions, business expenses, reconciliation, return impact and evidence state." />
    <section className="metric-grid"><article className="metric"><div className="metric-top"><span className="metric-label">Expenses</span><span className="metric-icon">E</span></div><div className="metric-value">{business.expenses.length}</div><div className="metric-foot">{formatMoney(Number(business.metrics.expense_value_cents ?? 0))} recorded</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Input VAT</span><span className="metric-icon">V</span></div><div className="metric-value">{formatMoney(inputVat)}</div><div className="metric-foot">Across visible VAT periods</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Unmatched items</span><span className="metric-icon">!</span></div><div className="metric-value">{unmatched}</div><div className="metric-foot warning">Review before claiming</div></article><article className="metric"><div className="metric-top"><span className="metric-label">Quarantined evidence</span><span className="metric-icon">D</span></div><div className="metric-value">{documents.quarantined}</div><div className="metric-foot">Unavailable until clean scan</div></article></section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Recent purchase and expense evidence</h2><div className="panel-meta">Amounts remain linked to their source and workflow status</div></div></div><div className="table-wrap"><table><thead><tr><th>Expense</th><th>Supplier</th><th>Category</th><th>Date</th><th>Tax</th><th>Total</th><th>Status</th></tr></thead><tbody>{business.expenses.map((item) => <tr key={String(item.id)}><td><strong>{String(item.expense_number)}</strong></td><td>{String(item.supplier_name ?? "Unassigned")}</td><td>{String(item.category_name)}</td><td>{String(item.expense_date)}</td><td className="amount">{formatMoney(Number(item.tax_cents), String(item.currency))}</td><td className="amount">{formatMoney(Number(item.total_cents), String(item.currency))}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
  </PortalShell>;
}
