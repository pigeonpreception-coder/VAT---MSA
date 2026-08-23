import type { Metadata } from "next";
import { PortalShell } from "@/components/PortalShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { getAuthorityGovernanceSnapshot } from "@/lib/data/authority-governance-repository";
import { getIdentityFoundationSnapshot } from "@/lib/data/identity-repository";
import { requirePortalAccess } from "@/lib/portals";
import { AuthorityGovernanceActions, type AuthorityOnboardingRow } from "./AuthorityGovernanceActions";

export const metadata: Metadata = { title: "NamRA administration portal" };
export const dynamic = "force-dynamic";

function value(input: unknown): string {
  return input === null || input === undefined ? "—" : String(input);
}

export default async function NamraAdminPortalPage() {
  const user = await getCurrentUser();
  await requirePortalAccess(user, "namra-admin");
  const [identity, governance] = await Promise.all([
    getIdentityFoundationSnapshot(user),
    getAuthorityGovernanceSnapshot(user),
  ]);
  const onboardingCases = governance.onboardingCases.map((item) => ({
    id: value(item.id),
    authority_name: value(item.authority_name),
    target_environment: value(item.target_environment),
    status: value(item.status),
    purpose: value(item.purpose),
    requester_name: value(item.requester_name),
    submitted_at: value(item.submitted_at),
    decision_type: item.decision_type === null ? null : value(item.decision_type),
    decision_reason: item.decision_reason === null ? null : value(item.decision_reason),
    decided_by_name: item.decided_by_name === null ? null : value(item.decided_by_name),
  })) satisfies AuthorityOnboardingRow[];

  return <PortalShell portalKey="namra-admin" user={user}>
    <PageHeader eyebrow="Tax Authority governance" title="Authority provisioning, federation and activation control" description="Authority hierarchy, protected roles, federation posture, independent onboarding decisions and quarterly access review. Live federation and production activation remain disabled until approved evidence exists." />

    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Assigned authorities</span><span className="metric-icon">A</span></div><div className="metric-value">{governance.authorities.length}</div><div className="metric-foot">Explicit administration scope</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Authority units</span><span className="metric-icon">H</span></div><div className="metric-value">{governance.units.length}</div><div className="metric-foot">Governed hierarchy</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Federation ready</span><span className="metric-icon">F</span></div><div className="metric-value">{governance.federation.filter((item) => item.status === "PRODUCTION_APPROVED").length}</div><div className="metric-foot warning">Contract and conformance required</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Production activated</span><span className="metric-icon">P</span></div><div className="metric-value">{governance.onboardingCases.filter((item) => item.status === "PRODUCTION_ACTIVATED").length}</div><div className="metric-foot warning">Disabled in this environment</div></article>
    </section>

    <div className="grid-2">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Authority hierarchy</h2><div className="panel-meta">Jurisdiction-scoped organisational units</div></div></div><div className="table-wrap"><table><thead><tr><th>Unit</th><th>Type</th><th>Parent</th><th>Status</th></tr></thead><tbody>
        {governance.units.map((item) => <tr key={value(item.id)}><td><strong>{value(item.name)}</strong><div className="mono muted">{value(item.code)}</div></td><td>{value(item.unit_type)}</td><td className="mono">{value(item.parent_unit_id)}</td><td><StatusBadge value={value(item.status)} /></td></tr>)}
      </tbody></table></div></section>

      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Federation registrations</h2><div className="panel-meta">Protocol is unconfirmed until the authority contract is approved</div></div></div><div className="table-wrap"><table><thead><tr><th>Provider</th><th>Environment</th><th>Protocol</th><th>Status</th></tr></thead><tbody>
        {governance.federation.map((item) => <tr key={value(item.id)}><td><strong>{value(item.display_name)}</strong><div className="mono muted">{value(item.provider_key)}</div></td><td>{value(item.environment)}</td><td>{value(item.protocol)}</td><td><StatusBadge value={value(item.status)} /></td></tr>)}
      </tbody></table></div></section>
    </div>

    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Protected administrative assignments</h2><div className="panel-meta">Maker and activation duties cannot be combined; all assignments retain approval evidence</div></div></div><div className="table-wrap"><table><thead><tr><th>Administrator</th><th>Role</th><th>Duty</th><th>Scope</th><th>Status</th></tr></thead><tbody>
      {governance.assignments.map((item) => <tr key={value(item.id)}><td><strong>{value(item.display_name)}</strong><div className="muted">{value(item.email)}</div></td><td>{value(item.role_name)}</td><td>{value(item.duty_class)}</td><td className="mono">{value(item.scope)}</td><td><StatusBadge value={value(item.status)} /></td></tr>)}
    </tbody></table></div></section>

    <AuthorityGovernanceActions cases={onboardingCases} />

    <div className="grid-2" style={{ marginTop: 20 }}>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Quarterly authority access review</h2><div className="panel-meta">Privileged decisions fail closed without a current review</div></div></div><div className="table-wrap"><table><thead><tr><th>Period</th><th>Due</th><th>Status</th></tr></thead><tbody>
        {governance.accessReviews.map((item) => <tr key={value(item.id)}><td>{value(item.period_start)}</td><td>{value(item.due_at)}</td><td><StatusBadge value={value(item.status)} /></td></tr>)}
      </tbody></table></div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Identity provider boundary</h2><div className="panel-meta">Authentication never grants authority by itself</div></div></div><div className="table-wrap"><table><thead><tr><th>Provider</th><th>Status</th><th>Configuration</th></tr></thead><tbody>
        {identity.providers.map((item) => <tr key={value(item.provider_key)}><td><strong>{value(item.display_name)}</strong><div className="mono muted">{value(item.provider_key)}</div></td><td><StatusBadge value={value(item.status)} /></td><td><StatusBadge value={value(item.configuration_status)} /></td></tr>)}
      </tbody></table></div></section>
    </div>

    <div className="alert alert-info" style={{ marginTop: 20 }}><strong>No implicit authority or financial access.</strong><br />A local-staging approval proves only the internal governance workflow. It does not activate ITAS federation, a production Tax Authority, statutory rules, taxpayer accounts, tax subscriptions or transaction access.</div>
  </PortalShell>;
}
