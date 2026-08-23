import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, hasPermission } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getPlatformSnapshot } from "@/lib/data/platform-repository";
import { formatDateTime } from "@/lib/format";
import { ReportRunner } from "./ReportRunner";

export const metadata: Metadata = { title: "Reports" };
export const dynamic = "force-dynamic";

export default async function ReportsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "reports:read", { operationClass: "READ" });
  const data = await getPlatformSnapshot(user);
  const definitions = data.reportDefinitions.map((item) => ({ code: String(item.code), name: String(item.name), description: String(item.description) }));

  return <AppShell active="reports" permission="reports:read">
    <PageHeader eyebrow="Governed analytics" title="Controlled operational and compliance reports" description="Report definitions are allow-listed and tenant-scoped. Inline summaries use bounded aggregate queries; export jobs require a separate worker and quarantined document pipeline." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Definitions</span><span className="metric-icon">R</span></div><div className="metric-value">{data.reportDefinitions.length}</div><div className="metric-foot">Versioned and allow-listed</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Completed inline</span><span className="metric-icon">C</span></div><div className="metric-value">{data.reportRuns.filter((item) => item.status === "COMPLETED_INLINE").length}</div><div className="metric-foot positive">Bounded D1 aggregates</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Failed</span><span className="metric-icon">!</span></div><div className="metric-value">{data.reportRuns.filter((item) => item.status === "FAILED").length}</div><div className="metric-foot">Visible execution outcomes</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Export worker</span><span className="metric-icon">X</span></div><div className="metric-value">Off</div><div className="metric-foot warning">External scanner required</div></article>
    </section>
    {hasPermission(user, "reports:run") ? <section className="panel registration-form-panel"><div className="panel-head"><div><h2 className="panel-title">Run controlled report</h2><div className="panel-meta">Current scope: {user.taxpayerId ?? "national authorised scope"}</div></div></div><div style={{ padding: 20 }}><ReportRunner definitions={definitions} /></div></section> : null}
    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Execution register</h2><div className="panel-meta">Parameters and result summaries are retained for traceability</div></div></div>{data.reportRuns.length ? <div className="table-wrap"><table><thead><tr><th>Report</th><th>Status</th><th>Scope</th><th>Rows</th><th>Requested</th><th>Completed</th></tr></thead><tbody>{data.reportRuns.map((item) => <tr key={String(item.id)}><td><strong>{String(item.name)}</strong><div className="mono muted">{String(item.code)}</div></td><td><StatusBadge value={String(item.status)} /></td><td>{String(item.taxpayer_id ?? "National")}</td><td>{Number(item.row_count ?? 0)}</td><td>{formatDateTime(String(item.requested_at))}</td><td>{item.completed_at ? formatDateTime(String(item.completed_at)) : "Pending"}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No report runs</strong>Choose a definition to create the first bounded report.</div>}</section>
  </AppShell>;
}
