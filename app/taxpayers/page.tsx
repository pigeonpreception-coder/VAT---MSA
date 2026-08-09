import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { listTaxpayers } from "@/lib/data/repository";
import { formatMoney } from "@/lib/format";
import { getCurrentUser, requirePermission } from "@/lib/auth";

export const metadata: Metadata = { title: "Taxpayer registry" };
export const dynamic = "force-dynamic";

export default async function TaxpayersPage() {
  const user = await getCurrentUser();
  requirePermission(user, "taxpayers:read");
  const taxpayers = await listTaxpayers();
  return <AppShell active="taxpayers" permission="taxpayers:read">
    <PageHeader eyebrow="Identity and taxpayer domain" title="Canonical taxpayer registry" description="VAT numbers, TINs and legal identities resolve to immutable internal taxpayer records used across every transaction." />
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Pilot participants</h2><div className="panel-meta">{taxpayers.length} active registrations available for controlled transactions</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Taxpayer</th><th>VAT number</th><th>TIN</th><th>Type</th><th>Frequency</th><th>Transactions</th><th>Output VAT</th><th>Input VAT</th><th>Status</th></tr></thead>
      <tbody>{taxpayers.map((taxpayer) => <tr key={String(taxpayer.id)}>
        <td><strong>{String(taxpayer.legal_name)}</strong><div className="muted">{String(taxpayer.trading_name ?? taxpayer.email)}</div></td>
        <td className="mono">{String(taxpayer.vat_number)}</td><td className="mono">{String(taxpayer.tin)}</td>
        <td>{String(taxpayer.taxpayer_type).replaceAll("_", " ")}</td><td>{String(taxpayer.return_frequency)}</td>
        <td>{Number(taxpayer.transaction_count)}</td><td className="amount">{formatMoney(Number(taxpayer.output_tax_cents))}</td><td className="amount">{formatMoney(Number(taxpayer.input_tax_cents))}</td><td><StatusBadge value={String(taxpayer.vat_status)} /></td>
      </tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
