import type { Metadata } from "next";
import Link from "next/link";
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
    <PageHeader eyebrow="Identity and taxpayer domain" title="Canonical taxpayer registry" description="One legal taxpayer identity resolves to one organisation used across every buyer and seller transaction role." />
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Pilot participants</h2><div className="panel-meta">{taxpayers.length} active registrations with canonical organisation mappings</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Taxpayer</th><th>VAT number</th><th>TIN</th><th>Capabilities</th><th>Frequency</th><th>Transactions</th><th>Output VAT</th><th>Input VAT</th><th>Status</th></tr></thead>
      <tbody>{taxpayers.map((taxpayer) => <tr key={String(taxpayer.id)}>
        <td>{taxpayer.organisation_id ? <Link href={`/organisations/${String(taxpayer.organisation_id)}`}><strong>{String(taxpayer.legal_name)}</strong></Link> : <strong>{String(taxpayer.legal_name)}</strong>}<div className="muted">{String(taxpayer.trading_name ?? taxpayer.email)}</div></td>
        <td className="mono">{String(taxpayer.vat_number)}</td><td className="mono">{String(taxpayer.tin)}</td>
        <td><div className="capability-list">{String(taxpayer.capabilities).split(",").filter(Boolean).map((capability) => <StatusBadge key={capability} value={capability} />)}</div></td><td>{String(taxpayer.return_frequency)}</td>
        <td>{Number(taxpayer.transaction_count)}</td><td className="amount">{formatMoney(Number(taxpayer.output_tax_cents))}</td><td className="amount">{formatMoney(Number(taxpayer.input_tax_cents))}</td><td><StatusBadge value={String(taxpayer.vat_status)} /></td>
      </tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
