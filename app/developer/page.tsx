import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getPlatformSnapshot } from "@/lib/data/platform-repository";
import { formatDateTime } from "@/lib/format";

export const metadata: Metadata = { title: "Developer and webhooks" };
export const dynamic = "force-dynamic";

export default async function DeveloperPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "developer:read", { operationClass: "READ" });
  const data = await getPlatformSnapshot(user);
  const pendingEvents = Number(data.outbox.find((item) => item.status === "PENDING")?.count ?? 0);

  return <AppShell active="developer" permission="developer:read">
    <PageHeader eyebrow="Controlled interoperability" title="API clients, webhook contracts and durable delivery" description="Machine access is deny-by-default. Client credentials remain in an external secret manager, webhook delivery is signed, and domain events first commit to a transactional outbox." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">API clients</span><span className="metric-icon">A</span></div><div className="metric-value">{data.clients.length}</div><div className="metric-foot">Scoped machine identities</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Active clients</span><span className="metric-icon">K</span></div><div className="metric-value">{data.clients.filter((item) => item.status === "ACTIVE").length}</div><div className="metric-foot">Credentials must be externally provisioned</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Webhooks</span><span className="metric-icon">W</span></div><div className="metric-value">{data.webhooks.length}</div><div className="metric-foot">Signed delivery subscriptions</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Outbox pending</span><span className="metric-icon">E</span></div><div className="metric-value">{pendingEvents}</div><div className="metric-foot warning">Consumer connection pending</div></article>
    </section>
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">API client registry</h2><div className="panel-meta">Secret values never appear in this registry</div></div></div>{data.clients.length ? <div className="table-wrap"><table><thead><tr><th>Client</th><th>Organisation</th><th>Scopes</th><th>Credential reference</th><th>Status</th><th>Last rotated</th></tr></thead><tbody>{data.clients.map((item) => <tr key={String(item.id)}><td><strong>{String(item.name)}</strong><div className="mono muted">{String(item.client_key)}</div></td><td>{String(item.legal_name ?? item.organisation_id)}</td><td className="mono">{String(item.scopes)}</td><td><StatusBadge value={item.credential_reference ? "EXTERNAL_REFERENCE" : "MISSING"} /></td><td><StatusBadge value={String(item.status)} /></td><td>{item.last_rotated_at ? formatDateTime(String(item.last_rotated_at)) : "Never"}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No API clients</strong>Provisioning requires developer management permission and an external secret manager.</div>}</section>
    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Webhook subscriptions</h2><div className="panel-meta">HTTPS, signing-key references, retries and dead-letter handling are mandatory</div></div></div>{data.webhooks.length ? <div className="table-wrap"><table><thead><tr><th>Endpoint</th><th>Events</th><th>Signing key</th><th>Status</th><th>Created</th></tr></thead><tbody>{data.webhooks.map((item) => <tr key={String(item.id)}><td className="mono">{String(item.endpoint_url)}</td><td className="mono">{String(item.event_types)}</td><td><StatusBadge value={item.signing_key_reference ? "EXTERNAL_REFERENCE" : "MISSING"} /></td><td><StatusBadge value={String(item.status)} /></td><td>{formatDateTime(String(item.created_at))}</td></tr>)}</tbody></table></div> : <div className="empty"><strong>No webhook subscriptions</strong>No external endpoint will receive events until its contract and signing key are configured.</div>}</section>
    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>Publishing remains safely queued.</strong><br />The outbox is durable, but no external event-bus or webhook worker is configured. Events are preserved instead of being marked delivered without evidence.</div>
  </AppShell>;
}
