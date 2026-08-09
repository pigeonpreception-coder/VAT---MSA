import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getVatReturnDetail, VatLifecycleResourceError } from "@/lib/data/vat-lifecycle-repository";
import { formatDateTime, formatMoney } from "@/lib/format";

export const metadata: Metadata = { title: "VAT return evidence" };
export const dynamic = "force-dynamic";

export default async function VatReturnDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await getCurrentUser();
  requirePermission(user, "returns:read");
  const { id } = await params;
  let detail;
  try { detail = await getVatReturnDetail(id, user); } catch (error) { if (error instanceof VatLifecycleResourceError && error.status === 404) notFound(); throw error; }
  const version = detail.version;
  return <AppShell active="returns" permission="returns:read">
    <PageHeader eyebrow={`VAT return ${version.period_code} · version ${version.version_number}`} title="Return calculation evidence" description="Every displayed box is tied to its calculation trace, source count, ledger snapshot hash and pinned tax rule version." />
    <section className="detail-grid">
      <div className="detail"><span className="detail-label">Status</span><StatusBadge value={version.status} /></div>
      <div className="detail"><span className="detail-label">VAT number</span><strong className="mono">{version.vat_number}</strong></div>
      <div className="detail"><span className="detail-label">Generated</span><strong>{formatDateTime(String((version as unknown as { generated_at: string }).generated_at))}</strong></div>
      <div className="detail" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Ledger snapshot hash</span><strong className="mono">{version.ledger_snapshot_hash}</strong></div>
    </section>
    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Return boxes</h2><div className="panel-meta">Integer-cent calculation evidence</div></div></div><div className="table-wrap"><table><thead><tr><th>Box</th><th>Label</th><th>Amount</th><th>Sources</th><th>Calculation trace</th></tr></thead><tbody>{detail.boxes.map((box) => <tr key={String(box.id)}><td className="mono"><strong>{String(box.box_code)}</strong></td><td>{String(box.label)}</td><td className="amount">{formatMoney(Number(box.amount_cents))}</td><td>{Number(box.source_count)}</td><td className="mono">{String(box.calculation_trace)}</td></tr>)}</tbody></table></div></section>
    <div className="grid-equal" style={{ marginTop: 20 }}><section className="panel"><div className="panel-head"><div><h2 className="panel-title">Adjustment evidence</h2><div className="panel-meta">Only approved adjustments affect a version</div></div></div><div className="table-wrap"><table><thead><tr><th>Type</th><th>Direction</th><th>Amount</th><th>Reason</th><th>Status</th></tr></thead><tbody>{detail.adjustments.map((item) => <tr key={String(item.id)}><td>{String(item.adjustment_type).replaceAll("_", " ")}</td><td>{String(item.direction)}</td><td className="amount">{formatMoney(Number(item.amount_cents))}</td><td>{String(item.reason_code)}<div className="muted">{String(item.explanation)}</div></td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section><section className="panel"><div className="panel-head"><div><h2 className="panel-title">Provider submissions</h2><div className="panel-meta">No legal filing status without provider acknowledgement</div></div></div><div className="table-wrap"><table><thead><tr><th>Provider</th><th>Reference</th><th>Requested</th><th>Status</th><th>Provider reference</th></tr></thead><tbody>{detail.submissions.map((item) => <tr key={String(item.id)}><td>{String(item.provider)}</td><td className="mono">{String(item.request_reference)}</td><td>{formatDateTime(String(item.requested_at))}</td><td><StatusBadge value={String(item.status)} /></td><td>{String(item.provider_reference ?? item.last_error ?? "Pending")}</td></tr>)}</tbody></table></div></section></div>
  </AppShell>;
}
