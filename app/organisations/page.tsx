import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getIdentityFoundationSnapshot } from "@/lib/data/identity-repository";

export const metadata: Metadata = { title: "Identity foundation" };
export const dynamic = "force-dynamic";

export default async function OrganisationsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "identity:read");
  const snapshot = await getIdentityFoundationSnapshot(user);
  const activeBranches = Number(snapshot.access.active_branches ?? 0);
  const activeLinks = Number(snapshot.access.active_identity_links ?? 0);
  const pending = snapshot.registrations.filter((item) => !["APPROVED", "REJECTED", "CANCELLED"].includes(item.status)).length;

  return <AppShell active="organisations" permission="identity:read">
    <PageHeader
      eyebrow="Identity and organisation domain"
      title="One taxpayer. One organisation."
      description="Canonical legal identities, branches, people and transaction capabilities are resolved here before fiscal activity is authorised. Buyer and seller remain dynamic capabilities—not separate accounts."
      actions={<Link className="btn btn-primary" href="/registrations/new">New registration</Link>}
    />

    <section className="metric-grid" aria-label="Identity foundation metrics">
      <article className="metric"><div className="metric-top"><span className="metric-label">Canonical organisations</span><span className="metric-icon">O</span></div><div className="metric-value">{snapshot.organisations.length}</div><div className="metric-foot positive">One-to-one taxpayer mappings</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Active branches</span><span className="metric-icon">B</span></div><div className="metric-value">{activeBranches}</div><div className="metric-foot">Branch-scoped access boundary</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Linked identities</span><span className="metric-icon">ID</span></div><div className="metric-value">{activeLinks}</div><div className="metric-foot">Provider subject links—not email identity</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Pending registrations</span><span className="metric-icon">!</span></div><div className="metric-value">{pending}</div><div className="metric-foot warning">No auto-activation before authority checks</div></article>
    </section>

    <div className="grid-2">
      <section className="panel">
        <div className="panel-head"><div><h2 className="panel-title">Organisation registry</h2><div className="panel-meta">Legal identity with dynamic transaction capabilities</div></div></div>
        <div className="table-wrap"><table><thead><tr><th>Organisation</th><th>VAT / TIN</th><th>Capabilities</th><th>Branches</th><th>Members</th><th>Status</th></tr></thead>
          <tbody>{snapshot.organisations.map((organisation) => <tr key={organisation.id}>
            <td><Link href={`/organisations/${organisation.id}`}><strong>{organisation.legal_name}</strong></Link><div className="muted mono">{organisation.id}</div></td>
            <td><span className="mono">{organisation.vat_number}</span><div className="muted mono">{organisation.tin}</div></td>
            <td><div className="capability-list">{organisation.capabilities.split(",").filter(Boolean).map((capability) => <StatusBadge key={capability} value={capability} />)}</div></td>
            <td>{organisation.branch_count}</td><td>{organisation.member_count}</td><td><StatusBadge value={organisation.status} /></td>
          </tr>)}</tbody></table></div>
      </section>

      <aside className="panel">
        <div className="panel-head"><div><h2 className="panel-title">Identity providers</h2><div className="panel-meta">Authentication boundary and authority status</div></div></div>
        <div className="panel-body provider-list">
          {snapshot.providers.map((provider) => <article className="provider-card" key={String(provider.provider_key)}>
            <div><strong>{String(provider.display_name)}</strong><p>{String(provider.authority_level).replaceAll("_", " ")} · {String(provider.provider_type).replaceAll("_", " ")}</p></div>
            <StatusBadge value={String(provider.configuration_status)} />
          </article>)}
          <div className="alert alert-info"><strong>ITAS integration boundary is ready.</strong><br />Live federation and taxpayer verification remain disabled until NamRA/ITAS confirms the protocol, claims and authoritative response contract.</div>
        </div>
      </aside>
    </div>
  </AppShell>;
}
