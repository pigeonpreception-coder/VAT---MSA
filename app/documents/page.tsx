import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, hasPermission } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getPlatformSnapshot } from "@/lib/data/platform-repository";
import { formatDateTime } from "@/lib/format";
import { DocumentUploadForm } from "./DocumentUploadForm";

export const metadata: Metadata = { title: "Evidence documents" };
export const dynamic = "force-dynamic";

export default async function DocumentsPage({ searchParams }: { searchParams: Promise<{ owner_domain?: string; owner_resource_id?: string }> }) {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "documents:read", { operationClass: "READ" });
  const data = await getPlatformSnapshot(user);
  const requestedOwner = await searchParams;
  const defaultOwnerDomain = requestedOwner.owner_domain === "EXPENSE" ? "EXPENSE" : "";
  const defaultOwnerResourceId = defaultOwnerDomain ? (requestedOwner.owner_resource_id?.trim() ?? "") : "";

  return <AppShell active="documents" permission="documents:read">
    <PageHeader eyebrow="Evidence custody" title="Private documents, integrity checks and quarantine" description="Evidence objects are private, checksummed and classified. New uploads remain quarantined until a separately configured malware scanner records a clean result; this application never guesses that outcome." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Documents</span><span className="metric-icon">D</span></div><div className="metric-value">{data.documents.length}</div><div className="metric-foot">Tenant-scoped metadata records</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Quarantined</span><span className="metric-icon">Q</span></div><div className="metric-value">{data.documents.filter((item) => item.status === "QUARANTINED").length}</div><div className="metric-foot warning">Not available for consumption</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Clean</span><span className="metric-icon">C</span></div><div className="metric-value">{data.documents.filter((item) => item.scan_status === "CLEAN").length}</div><div className="metric-foot">Verified by an external scanner</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Legal holds</span><span className="metric-icon">L</span></div><div className="metric-value">{data.documents.filter((item) => Number(item.legal_hold) === 1).length}</div><div className="metric-foot">Deletion protection</div></article>
    </section>
    {hasPermission(user, "documents:upload") ? <section className="panel registration-form-panel"><div className="panel-head"><div><h2 className="panel-title">Add evidence</h2><div className="panel-meta">Object storage write followed by atomic metadata, audit and outbox records</div></div></div><div style={{ padding: 20 }}><DocumentUploadForm defaultOwnerDomain={defaultOwnerDomain} defaultOwnerResourceId={defaultOwnerResourceId} /></div></section> : null}
    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Evidence register</h2><div className="panel-meta">Downloads are unavailable while malware scanning is not configured</div></div></div>{data.documents.length ? <div className="table-wrap"><table><thead><tr><th>File</th><th>Owner</th><th>Classification</th><th>Scan</th><th>Status</th><th>Size</th><th>Uploaded</th></tr></thead><tbody>{data.documents.map((item) => <tr key={String(item.id)}><td><strong>{String(item.file_name)}</strong><div className="mono muted">{String(item.checksum_sha256).slice(0, 16)}...</div></td><td>{String(item.owner_domain).replaceAll("_", " ")}<div className="mono muted">{String(item.owner_resource_id)}</div></td><td><StatusBadge value={String(item.classification)} /></td><td><StatusBadge value={String(item.scan_status)} /></td><td><StatusBadge value={String(item.status)} /></td><td>{Number(item.size_bytes).toLocaleString()} bytes</td><td>{formatDateTime(String(item.uploaded_at))}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No evidence documents</strong>Use the governed upload to create a quarantined evidence object.</div>}</section>
  </AppShell>;
}
