import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getBusinessPlatformSnapshot } from "@/lib/data/business-repository";
import { formatMoney } from "@/lib/format";

export const metadata: Metadata = { title: "Business operations" };
export const dynamic = "force-dynamic";

function quantity(micros: unknown) {
  return (Number(micros) / 1_000_000).toLocaleString("en-NA", { maximumFractionDigits: 6 });
}

export default async function OperationsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "expenses:read");
  const snapshot = await getBusinessPlatformSnapshot(user);
  return <AppShell active="operations" permission="expenses:read">
    <PageHeader eyebrow="Operational domains" title="Expenses, inventory, projects and imports" description="Operational evidence remains linked to the organisation, branch, project and source document so VAT treatment and accounting postings can be reconstructed rather than inferred." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Expenses</span><span className="metric-icon">E</span></div><div className="metric-value">{Number(snapshot.metrics.expenses ?? 0)}</div><div className="metric-foot">{formatMoney(Number(snapshot.metrics.expense_value_cents ?? 0))} recorded</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Inventory items</span><span className="metric-icon">I</span></div><div className="metric-value">{snapshot.balances.length}</div><div className="metric-foot">Non-negative database invariant</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Active projects</span><span className="metric-icon">P</span></div><div className="metric-value">{Number(snapshot.metrics.projects ?? 0)}</div><div className="metric-foot">Budget-to-cost traceability</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Import declarations</span><span className="metric-icon">C</span></div><div className="metric-value">{snapshot.imports.length}</div><div className="metric-foot warning">Evidence-gated input VAT</div></article>
    </section>
    <div className="grid-equal">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Expense register</h2><div className="panel-meta">Approval and receipt status preserved</div></div></div><div className="table-wrap"><table><thead><tr><th>Expense</th><th>Date</th><th>Category / supplier</th><th>Description</th><th>Total</th><th>Status</th></tr></thead><tbody>{snapshot.expenses.map((item) => <tr key={String(item.id)}><td><strong>{String(item.expense_number)}</strong></td><td>{String(item.expense_date)}</td><td>{String(item.category_name)}<div className="muted">{String(item.supplier_name ?? "No supplier")}</div></td><td>{String(item.description)}</td><td className="amount">{formatMoney(Number(item.total_cents), String(item.currency))}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Inventory balances</h2><div className="panel-meta">Signed stock movements update versioned balances</div></div></div><div className="table-wrap"><table><thead><tr><th>Warehouse</th><th>Product</th><th>SKU</th><th>On hand</th><th>Average cost</th><th>Version</th></tr></thead><tbody>{snapshot.balances.map((item) => <tr key={String(item.id)}><td>{String(item.warehouse_name)}</td><td><strong>{String(item.product_name)}</strong></td><td className="mono">{String(item.sku)}</td><td className="amount">{quantity(item.quantity_micros)}</td><td className="amount">{formatMoney(Number(item.average_cost_cents))}</td><td>{Number(item.version)}</td></tr>)}</tbody></table></div></section>
    </div>
    <div className="grid-equal" style={{ marginTop: 20 }}>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Project control</h2><div className="panel-meta">Budget and actual costs by source</div></div></div><div className="table-wrap"><table><thead><tr><th>Project</th><th>Customer</th><th>Period</th><th>Budget</th><th>Cost</th><th>Status</th></tr></thead><tbody>{snapshot.projects.map((item) => <tr key={String(item.id)}><td><strong>{String(item.code)}</strong><div>{String(item.name)}</div></td><td>{String(item.customer_name ?? "Internal")}</td><td>{String(item.start_date)}<div className="muted">{String(item.end_date ?? "Open")}</div></td><td className="amount">{formatMoney(Number(item.budget_cents), String(item.currency))}</td><td className="amount">{formatMoney(Number(item.cost_cents), String(item.currency))}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Import VAT evidence</h2><div className="panel-meta">Customs claims cannot be treated as verified without evidence</div></div></div><div className="table-wrap"><table><thead><tr><th>Declaration</th><th>Supplier / origin</th><th>Customs value</th><th>Import VAT</th><th>Date</th><th>Status</th></tr></thead><tbody>{snapshot.imports.map((item) => <tr key={String(item.id)}><td className="mono"><strong>{String(item.declaration_number)}</strong></td><td>{String(item.supplier_name)}<div className="muted">{String(item.country_of_origin)}</div></td><td className="amount">{formatMoney(Number(item.customs_value_cents), String(item.currency))}</td><td className="amount">{formatMoney(Number(item.import_vat_cents), String(item.currency))}</td><td>{String(item.declaration_date)}</td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
    </div>
  </AppShell>;
}
