import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { listAuditEvents } from "@/lib/data/repository";
import { formatDateTime } from "@/lib/format";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Audit evidence" };
export const dynamic = "force-dynamic";

function detailSummary(value: string): string {
  try {
    const object = JSON.parse(value) as Record<string, unknown>;
    return Object.entries(object).slice(0, 3).map(([key, item]) => `${key.replaceAll("_", " ")}: ${String(item)}`).join(" · ");
  } catch { return value; }
}

export default async function AuditPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "audit:read", { operationClass: "READ" });
  const events = await listAuditEvents();
  return <AppShell active="audit" permission="audit:read">
    <PageHeader eyebrow="Tamper-evident evidence" title="Append-only audit stream" description="Security and business events are chained by hash so changes to historical evidence can be detected and investigated." />
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Recorded evidence</h2><div className="panel-meta">{events.length} events · latest first · ordinary application roles have no update or delete path</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Occurred</th><th>Action</th><th>Actor</th><th>Resource</th><th>Outcome</th><th>Evidence summary</th><th>Event hash</th></tr></thead>
      <tbody>{events.map((event) => <tr key={String(event.id)}><td>{formatDateTime(String(event.occurred_at))}</td><td><strong>{String(event.action).replaceAll("_", " ")}</strong></td><td>{String(event.actor_id)}<div className="muted">{String(event.actor_role)}</div></td><td>{String(event.resource_type)}<div className="mono muted">{String(event.resource_id)}</div></td><td><StatusBadge value={String(event.outcome)} /></td><td style={{ minWidth: 260 }}>{detailSummary(String(event.details))}</td><td className="mono" title={String(event.event_hash)}>{String(event.event_hash).slice(0, 16)}…</td></tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
