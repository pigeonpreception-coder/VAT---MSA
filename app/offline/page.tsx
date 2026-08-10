import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getPlatformSnapshot } from "@/lib/data/platform-repository";
import { formatDateTime } from "@/lib/format";

export const metadata: Metadata = { title: "Offline continuity" };
export const dynamic = "force-dynamic";

export default async function OfflinePage() {
  const user = await getCurrentUser();
  requirePermission(user, "offline:read");
  const data = await getPlatformSnapshot(user);

  return <AppShell active="offline" permission="offline:read">
    <PageHeader eyebrow="Business continuity" title="Offline devices, number ranges and ordered synchronisation" description="Offline documents require an enrolled device, verified public key, reserved number range, contiguous sequence, hash-chain continuity and a valid signature before fiscal processing." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Devices</span><span className="metric-icon">D</span></div><div className="metric-value">{data.devices.length}</div><div className="metric-foot">Trust bootstrap required</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Active ranges</span><span className="metric-icon">#</span></div><div className="metric-value">{data.numberRanges.filter((item) => item.status === "ACTIVE").length}</div><div className="metric-foot">Reserved document numbers</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Rejected batches</span><span className="metric-icon">R</span></div><div className="metric-value">{data.batches.filter((item) => item.status === "REJECTED").length}</div><div className="metric-foot warning">Preserved for security evidence</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Open conflicts</span><span className="metric-icon">!</span></div><div className="metric-value">{data.conflicts.filter((item) => item.status === "OPEN").length}</div><div className="metric-foot">Human resolution queue</div></article>
    </section>
    <div className="grid-equal">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Device registry</h2><div className="panel-meta">No device may self-enrol</div></div></div><div className="table-wrap"><table><thead><tr><th>Device</th><th>Organisation</th><th>Enrolment</th><th>Status</th><th>Last sequence</th><th>Last seen</th></tr></thead><tbody>{data.devices.map((item) => <tr key={String(item.id)}><td><strong>{String(item.display_name)}</strong><div className="mono muted">{String(item.device_code)}</div></td><td>{String(item.legal_name ?? item.organisation_id)}</td><td><StatusBadge value={String(item.enrolment_status)} /></td><td><StatusBadge value={String(item.status)} /></td><td>{Number(item.last_accepted_sequence)}</td><td>{item.last_seen_at ? formatDateTime(String(item.last_seen_at)) : "Never"}</td></tr>)}</tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Number ranges</h2><div className="panel-meta">Held until device trust is verified</div></div></div><div className="table-wrap"><table><thead><tr><th>Document</th><th>Prefix</th><th>Range</th><th>Next</th><th>Valid</th><th>Status</th></tr></thead><tbody>{data.numberRanges.map((item) => <tr key={String(item.id)}><td>{String(item.document_type).replaceAll("_", " ")}</td><td className="mono">{String(item.prefix)}</td><td>{Number(item.range_start)}-{Number(item.range_end)}</td><td>{Number(item.next_number)}</td><td>{String(item.valid_from)}<div className="muted">to {String(item.valid_to)}</div></td><td><StatusBadge value={String(item.status)} /></td></tr>)}</tbody></table></div></section>
    </div>
    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Batch intake</h2><div className="panel-meta">Rejected attempts remain auditable</div></div></div>{data.batches.length ? <div className="table-wrap"><table><thead><tr><th>Batch</th><th>Sequence</th><th>Documents</th><th>Status</th><th>Reason</th><th>Received</th></tr></thead><tbody>{data.batches.map((item) => <tr key={String(item.id)}><td className="mono">{String(item.client_batch_id)}</td><td>{Number(item.sequence_from)}-{Number(item.sequence_to)}</td><td>{Number(item.document_count)}</td><td><StatusBadge value={String(item.status)} /></td><td>{String(item.rejection_reason ?? "-")}</td><td>{formatDateTime(String(item.received_at))}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No offline batches received</strong>Batch validation is available through the versioned API.</div>}</section>
  </AppShell>;
}
