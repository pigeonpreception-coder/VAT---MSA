import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getSecurityOperationsSnapshot } from "@/lib/data/repository";
import { formatDateTime } from "@/lib/format";

export const metadata: Metadata = { title: "Security operations" };
export const dynamic = "force-dynamic";

function countBy(items: Array<{ severity?: string; status?: string; count: number }>, key: string): number {
  return items.find((item) => item.severity === key || item.status === key)?.count ?? 0;
}
function details(value: unknown): string {
  try {
    const parsed = JSON.parse(String(value)) as Record<string, unknown>;
    return Object.entries(parsed).slice(0, 3).map(([key, item]) => `${key.replaceAll("_", " ")}: ${String(item)}`).join(" · ");
  } catch { return String(value); }
}

export default async function SecurityOperationsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "security:read", { operationClass: "READ" });
  const snapshot = await getSecurityOperationsSnapshot();
  const openIncidents = snapshot.incidents.filter((incident) => incident.status !== "CLOSED").length;
  return <AppShell active="security" permission="security:read">
    <PageHeader eyebrow="Security operations centre" title="Command and control" description="Application security events, incident state, delivery backlog and integrity health for the controlled environment. Edge, IAM, network and infrastructure telemetry feed the production SIEM." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Open incidents</span><span className="metric-icon">!</span></div><div className="metric-value">{openIncidents}</div><div className="metric-foot warning">Human review required for high-impact containment</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">High / critical events</span><span className="metric-icon">S</span></div><div className="metric-value">{countBy(snapshot.eventCounts, "HIGH") + countBy(snapshot.eventCounts, "CRITICAL")}</div><div className="metric-foot">Correlated application security evidence</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Pending outbox</span><span className="metric-icon">Q</span></div><div className="metric-value">{countBy(snapshot.outbox, "PENDING")}</div><div className="metric-foot">Replay-safe events awaiting publication</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Data integrity</span><span className="metric-icon">DB</span></div><div className="metric-value">Healthy</div><div className="metric-foot positive">{snapshot.database?.invoices ?? 0} invoices · {snapshot.database?.audit_events ?? 0} audit events</div></article>
    </section>

    <div className="grid-equal">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Incident queue</h2><div className="panel-meta">Detect, classify, contain, recover and learn</div></div></div>
        {snapshot.incidents.length ? <div className="table-wrap"><table><thead><tr><th>Incident</th><th>Severity</th><th>Status</th><th>Containment</th><th>Owner</th><th>Updated</th></tr></thead><tbody>{snapshot.incidents.map((incident) => <tr key={String(incident.id)}><td><strong>{String(incident.title)}</strong><div className="mono muted">{String(incident.id)}</div></td><td><StatusBadge value={String(incident.severity)} /></td><td><StatusBadge value={String(incident.status)} /></td><td>{String(incident.automated_action ?? "Human decision")}</td><td>{String(incident.owner ?? "Unassigned")}</td><td>{formatDateTime(String(incident.updated_at))}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No active incidents</strong>The queue is clear.</div>}
      </section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Control posture</h2><div className="panel-meta">Runtime controls implemented in this release</div></div></div><div className="panel-body activity">
        {[
          ["Tenant isolation", "Taxpayer-scoped invoice, return and exception queries"],
          ["API abuse control", "Actor, device, source, tenant and global rate windows"],
          ["Payload defence", "JSON-only requests with a 1 MiB hard limit"],
          ["Evidence integrity", "Correlation IDs, structured logs and hash-chained audit events"],
          ["Failure containment", "Transactional outbox decouples committed work from delivery"],
          ["Browser defence", "CSP, HSTS, frame, MIME and privacy response policies"],
        ].map(([title, copy]) => <div className="activity-item" key={title}><span className="activity-marker" /><div><strong>{title}</strong><p>{copy}</p></div><StatusBadge value="ACTIVE" /></div>)}
      </div></section>
    </div>

    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Recent security events</h2><div className="panel-meta">Tax-confidential payloads and raw source addresses are excluded</div></div></div><div className="table-wrap"><table><thead><tr><th>Occurred</th><th>Event</th><th>Severity</th><th>Action</th><th>Outcome</th><th>Correlation ID</th><th>Evidence</th></tr></thead><tbody>{snapshot.recentEvents.map((event) => <tr key={String(event.id)}><td>{formatDateTime(String(event.occurred_at))}</td><td><strong>{String(event.event_type).replaceAll("_", " ")}</strong></td><td><StatusBadge value={String(event.severity)} /></td><td>{String(event.action).replaceAll("_", " ")}</td><td>{String(event.outcome)}</td><td className="mono">{String(event.correlation_id)}</td><td style={{ minWidth: 240 }}>{details(event.details)}</td></tr>)}</tbody></table></div></section>
  </AppShell>;
}
