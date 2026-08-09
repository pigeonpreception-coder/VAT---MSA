import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { listRegistrationApplications } from "@/lib/data/identity-repository";

export const metadata: Metadata = { title: "Registration intake" };
export const dynamic = "force-dynamic";

export default async function RegistrationsPage() {
  const user = await getCurrentUser();
  requirePermission(user, "registrations:read");
  const applications = await listRegistrationApplications(user);
  return <AppShell active="registrations" permission="registrations:read">
    <PageHeader eyebrow="Controlled onboarding" title="Taxpayer registration intake" description="Applications are deduplicated and held for authoritative verification. Submission never creates a taxpayer or organisation automatically." actions={<Link className="btn btn-primary" href="/registrations/new">New application</Link>} />
    <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Registration applications</h2><div className="panel-meta">{applications.length} controlled application{applications.length === 1 ? "" : "s"}</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Applicant</th><th>VAT number</th><th>TIN</th><th>Type</th><th>Verification</th><th>Submitted</th><th>Status</th></tr></thead><tbody>{applications.map((application) => <tr key={application.id}>
        <td><strong>{application.legal_name}</strong><div className="muted">{application.email}</div></td><td className="mono">{application.vat_number}</td><td className="mono">{application.tin}</td><td>{application.taxpayer_type.replaceAll("_", " ")}</td><td><StatusBadge value={application.verification_status ?? "NOT_STARTED"} /></td><td>{new Date(application.submitted_at).toLocaleString("en-NA")}</td><td><StatusBadge value={application.status} /></td>
      </tr>)}</tbody></table></div>
    </section>
  </AppShell>;
}
