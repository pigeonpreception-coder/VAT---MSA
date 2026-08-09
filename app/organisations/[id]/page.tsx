import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getOrganisation } from "@/lib/data/identity-repository";

export const metadata: Metadata = { title: "Organisation identity" };
export const dynamic = "force-dynamic";

export default async function OrganisationDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await getCurrentUser();
  requirePermission(user, "identity:read");
  const { id } = await params;
  const organisation = await getOrganisation(user, id);
  if (!organisation) notFound();

  return <AppShell active="organisations" permission="identity:read">
    <PageHeader eyebrow="Canonical organisation" title={organisation.legal_name} description="Authoritative taxpayer mapping, verified identifiers, dynamic trading capabilities, branches and scoped memberships." />
    <div className="detail-grid identity-summary">
      <div className="detail"><span className="detail-label">Organisation ID</span><strong className="mono">{organisation.id}</strong></div>
      <div className="detail"><span className="detail-label">VAT number</span><strong className="mono">{organisation.vat_number}</strong></div>
      <div className="detail"><span className="detail-label">TIN</span><strong className="mono">{organisation.tin}</strong></div>
      <div className="detail"><span className="detail-label">Taxpayer status</span><StatusBadge value={organisation.vat_status} /></div>
      <div className="detail"><span className="detail-label">Organisation status</span><StatusBadge value={organisation.status} /></div>
      <div className="detail"><span className="detail-label">Transaction capabilities</span><div className="capability-list">{organisation.capabilities.map((capability) => <StatusBadge key={String(capability.capability)} value={String(capability.capability)} />)}</div></div>
    </div>

    <div className="grid-equal identity-sections">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Authoritative identifiers</h2><div className="panel-meta">Source and verification evidence stay attached</div></div></div>
        <div className="table-wrap"><table><thead><tr><th>Type</th><th>Value</th><th>Source</th><th>Status</th></tr></thead><tbody>{organisation.identifiers.map((identifier) => <tr key={`${identifier.identifier_type}:${identifier.identifier_value}`}>
          <td>{String(identifier.identifier_type).replaceAll("_", " ")}</td><td className="mono">{String(identifier.identifier_value)}</td><td>{String(identifier.source).replaceAll("_", " ")}</td><td><StatusBadge value={String(identifier.status)} /></td>
        </tr>)}</tbody></table></div>
      </section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Branches</h2><div className="panel-meta">Operational and access-control scopes</div></div></div>
        <div className="table-wrap"><table><thead><tr><th>Code</th><th>Branch</th><th>Scope</th><th>Status</th></tr></thead><tbody>{organisation.branches.map((branch) => <tr key={String(branch.id)}>
          <td className="mono">{String(branch.code)}</td><td><strong>{String(branch.name)}</strong><div className="muted">{String(branch.address)}</div></td><td>{Number(branch.is_head_office) ? "Head office" : "Branch"}</td><td><StatusBadge value={String(branch.status)} /></td>
        </tr>)}</tbody></table></div>
      </section>
    </div>

    <section className="panel identity-sections"><div className="panel-head"><div><h2 className="panel-title">Organisation memberships</h2><div className="panel-meta">Roles are constrained by organisation, branch and validity period</div></div></div>
      {organisation.memberships.length ? <div className="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Branch scope</th><th>Valid from</th><th>Status</th></tr></thead><tbody>{organisation.memberships.map((membership) => <tr key={String(membership.id)}>
        <td><strong>{String(membership.display_name)}</strong><div className="muted">{String(membership.email)}</div></td><td>{String(membership.role_code).replaceAll("_", " ")}</td><td className="mono">{String(membership.branch_id ?? "All branches")}</td><td>{String(membership.valid_from)}</td><td><StatusBadge value={String(membership.status)} /></td>
      </tr>)}</tbody></table></div> : <div className="empty"><strong>No active memberships</strong>Memberships are provisioned through the approved identity and organisation workflow.</div>}
    </section>
  </AppShell>;
}
