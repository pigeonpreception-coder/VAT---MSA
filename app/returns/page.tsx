import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { listReturns } from "@/lib/data/repository";
import { formatDateTime, formatMoney } from "@/lib/format";
import { getCurrentUser, requirePermission } from "@/lib/auth";

export const metadata: Metadata = { title: "VAT returns" };
export const dynamic = "force-dynamic";

export default async function ReturnsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "returns:read");
  const returns = await listReturns(user);
  const output = returns.reduce((sum, item) => sum + Number(item.output_tax_cents), 0);
  const input = returns.reduce((sum, item) => sum + Number(item.input_tax_cents), 0);
  return <AppShell active="returns" permission="returns:read">
    <PageHeader eyebrow="VAT return engine" title="Period VAT positions" description="Transaction-ledger evidence is aggregated reproducibly into draft taxpayer positions. ITAS remains the statutory account and filing authority." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Output VAT</span><span className="metric-icon">O</span></div><div className="metric-value">{formatMoney(output)}</div><div className="metric-foot">Certified seller VAT</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Input candidates</span><span className="metric-icon">I</span></div><div className="metric-value">{formatMoney(input)}</div><div className="metric-foot">Registered-buyer ledger candidates</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Net position</span><span className="metric-icon">Σ</span></div><div className="metric-value">{formatMoney(output - input)}</div><div className="metric-foot">Pilot aggregate; not a statutory assessment</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Draft periods</span><span className="metric-icon">P</span></div><div className="metric-value">{returns.length}</div><div className="metric-foot">Recalculates as evidence is committed</div></article>
    </section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Taxpayer period summaries</h2><div className="panel-meta">Pilot period mapping; official return-form mapping awaits NamRA approval</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Taxpayer</th><th>VAT number</th><th>Period</th><th>Output VAT</th><th>Input VAT</th><th>Net payable / (refund)</th><th>Status</th><th>Calculated</th></tr></thead>
      <tbody>{returns.map((item) => <tr key={String(item.id)}><td><strong>{String(item.legal_name)}</strong></td><td className="mono">{String(item.vat_number)}</td><td>{String(item.period)}</td><td className="amount">{formatMoney(Number(item.output_tax_cents))}</td><td className="amount">{formatMoney(Number(item.input_tax_cents))}</td><td className="amount">{formatMoney(Number(item.net_payable_cents))}</td><td><StatusBadge value={String(item.status)} /></td><td>{formatDateTime(String(item.last_calculated_at))}</td></tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
