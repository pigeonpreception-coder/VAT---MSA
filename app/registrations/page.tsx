import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { isNationalScope } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { listRegistrationApplications } from "@/lib/data/identity-repository";
import { listSelfServeSignupApplications } from "@/lib/data/signup-repository";

export const metadata: Metadata = { title: "Registration intake" };
export const dynamic = "force-dynamic";

export default async function RegistrationsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "registrations:read", { operationClass: "READ" });
  const [applications, selfServeApplications] = await Promise.all([
    listRegistrationApplications(user),
    listSelfServeSignupApplications(user),
  ]);
  return <AppShell active="registrations" permission="registrations:read">
    <PageHeader eyebrow="Controlled onboarding" title="Taxpayer registration intake" description="Applications are deduplicated and held for authoritative verification. Submission never creates a taxpayer or organisation automatically." actions={<><Link className="btn btn-secondary" href="/signup">Open self-serve signup</Link><Link className="btn btn-primary" href="/registrations/new">New application</Link></>} />
    {isNationalScope(user) ? <section className="panel" style={{ marginBottom: 20 }}><div className="panel-head"><div><h2 className="panel-title">Self-serve signup queue</h2><div className="panel-meta">{selfServeApplications.length} pending or historical application{selfServeApplications.length === 1 ? "" : "s"} · no automatic activation</div></div></div>
      {selfServeApplications.length ? <div className="table-wrap"><table><thead><tr><th>Reference</th><th>Applicant</th><th>Organisation</th><th>VAT / TIN</th><th>Requested plan</th><th>Identity</th><th>Taxpayer verification</th><th>Licence</th><th>Submitted</th></tr></thead><tbody>{selfServeApplications.map((application) => <tr key={application.id}>
        <td className="mono"><strong>{application.public_reference}</strong><div><StatusBadge value={application.status} /></div></td>
        <td><strong>{application.applicant_name}</strong><div className="muted">{application.applicant_role.replaceAll("_", " ")} · {application.contact_email}</div></td>
        <td>{application.legal_name}</td><td><span className="mono">{application.vat_number}</span><div className="mono muted">{application.tin}</div></td>
        <td>{application.plan_name}<div className="mono muted">{application.plan_code}</div></td><td><StatusBadge value={application.identity_status} /></td><td><StatusBadge value={application.taxpayer_verification_status} /></td><td><StatusBadge value={application.licence_status} /></td><td>{new Date(application.submitted_at).toLocaleString("en-NA")}</td>
      </tr>)}</tbody></table></div> : <div className="empty"><strong>No self-serve applications</strong>Public submissions will appear here after validation and deduplication.</div>}
    </section> : null}
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Registration applications</h2><div className="panel-meta">{applications.length} controlled application{applications.length === 1 ? "" : "s"}</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Applicant</th><th>VAT number</th><th>TIN</th><th>Type</th><th>Provider verification</th><th>Identity proofing</th><th>Confidence</th><th>Mismatch</th><th>Submitted</th><th>Status</th></tr></thead><tbody>{applications.map((application) => <tr key={application.id}>
        <td><strong>{application.legal_name}</strong><div className="muted">{application.email}</div></td><td className="mono">{application.vat_number}</td><td className="mono">{application.tin}</td><td>{application.taxpayer_type.replaceAll("_", " ")}</td><td><StatusBadge value={application.verification_status ?? "NOT_STARTED"} /></td><td><StatusBadge value={application.proofing_status ?? "NOT_STARTED"} /><div className="muted">{(application.proofing_reason_code ?? "No proofing case").replaceAll("_", " ")}</div></td><td>{application.proofing_confidence_bps === null ? "—" : `${(application.proofing_confidence_bps / 100).toFixed(2)}%`}</td><td><StatusBadge value={application.mismatch_status ?? "NONE"} /></td><td>{new Date(application.submitted_at).toLocaleString("en-NA")}</td><td><StatusBadge value={application.status} /></td>
      </tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
