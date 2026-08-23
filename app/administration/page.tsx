import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser, hasPermission } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { formatDateTime } from "@/lib/format";
import { AdministrationActions } from "./AdministrationActions";

export const metadata: Metadata = { title: "Organisation administration" };
export const dynamic = "force-dynamic";

export default async function AdministrationPage() {
  const actor = await getCurrentUser();
  await requireLicensedPermission(actor, "administration:read", { operationClass: "READ" });
  const snapshot = await getAdministrationSnapshot(actor);
  const activeEmployees = snapshot.employees.filter((employee) => employee.status === "ACTIVE").length;
  const seat = snapshot.entitlements.find((entitlement) => entitlement.feature_key === "USER_SEATS");
  return <AppShell active="administration" permission="administration:read">
    <PageHeader eyebrow="Organisation control plane" title="Administration command centre" description="Licensed organisation structure, employment identity, least-privilege roles, immutable workflows and quarterly access governance. Employment position never grants access by itself." />
    <div className="alert alert-info admin-boundary"><strong>Local/staging safety boundary:</strong> synthetic data only. Real payments, outbound email, live ITAS connectivity and unapproved statutory rules remain disabled.</div>
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Licence</span><span className="metric-icon">L</span></div><div className="metric-value metric-text">{snapshot.license.plan_name}</div><div className="metric-foot positive">Price-free configurable placeholder · {snapshot.license.state}</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">User seats</span><span className="metric-icon">U</span></div><div className="metric-value">{Number(seat?.used_value ?? activeEmployees)} / {seat?.limit_value ?? "∞"}</div><div className="metric-foot">Invitations reserve capacity before activation</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Pending approvals</span><span className="metric-icon">W</span></div><div className="metric-value">{snapshot.tasks.length}</div><div className="metric-foot warning">Self-approval and emergency override disabled</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Access reviews</span><span className="metric-icon">Q</span></div><div className="metric-value">{snapshot.accessReviews.filter((review) => review.status === "OPEN").length}</div><div className="metric-foot">Quarterly certification cadence</div></article>
    </section>

    {hasPermission(actor, "employees:manage") && hasPermission(actor, "roles:manage") ? <AdministrationActions /> : null}

    <div className="admin-section-grid" id="employees">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Employees and employment structure</h2><div className="panel-meta">{snapshot.structures.departments ?? 0} departments · {snapshot.structures.branches ?? 0} branches · {snapshot.structures.job_titles ?? 0} job titles</div></div></div>
        <div className="table-wrap"><table><thead><tr><th>Employee</th><th>Employment</th><th>Last activity</th><th>Status</th></tr></thead><tbody>{snapshot.employees.map((employee) => <tr key={String(employee.id)}><td><strong>{String(employee.full_name)}</strong><div className="muted mono">{String(employee.employee_number)} · {String(employee.email)}</div></td><td>{String(employee.job_title ?? "Unassigned")}<div className="muted">{String(employee.department ?? "No department")} · {String(employee.branch ?? "No branch")}</div></td><td>{employee.last_activity_at ? formatDateTime(String(employee.last_activity_at)) : "Not yet active"}</td><td><StatusBadge value={String(employee.status)} /></td></tr>)}</tbody></table></div>
      </section>

      <section className="panel" id="roles"><div className="panel-head"><div><h2 className="panel-title">Organisation roles</h2><div className="panel-meta">Job titles and access roles remain separate</div></div></div>
        <div className="panel-body compact-list">{snapshot.roles.map((role) => <article className="control-list-item" key={String(role.id)}><div><strong>{String(role.name)}</strong><p>{String(role.description)}</p><span className="mono muted">{String(role.permissions || "No permissions")}</span></div><StatusBadge value={String(role.status)} /></article>)}</div>
      </section>
    </div>

    <div className="admin-section-grid">
      <section className="panel" id="workflows"><div className="panel-head"><div><h2 className="panel-title">Versioned workflows</h2><div className="panel-meta">Published versions are immutable and use typed conditions only</div></div></div><div className="panel-body compact-list">{snapshot.workflows.map((workflow) => <article className="control-list-item" key={`${String(workflow.id)}-${String(workflow.version_number)}`}><div><strong>{String(workflow.name)}</strong><p>{String(workflow.domain_action).replaceAll("_", " ")} · version {String(workflow.version_number ?? "draft")}</p></div><StatusBadge value={String(workflow.version_status ?? workflow.status)} /></article>)}</div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Access governance</h2><div className="panel-meta">Quarterly reviews and dual-control requests</div></div></div><div className="panel-body compact-list">{snapshot.accessReviews.map((review) => <article className="control-list-item" key={String(review.id)}><div><strong>{String(review.name)}</strong><p>{String(review.review_type)} · due {formatDateTime(String(review.due_at))}</p></div><StatusBadge value={String(review.status)} /></article>)}</div></section>
    </div>

    <section className="panel" id="licensing"><div className="panel-head"><div><h2 className="panel-title">Licence entitlements and usage</h2><div className="panel-meta">No prices configured · expiry is non-destructive</div></div><StatusBadge value={snapshot.license.state} /></div>
      <div className="table-wrap"><table><thead><tr><th>Feature</th><th>Meter</th><th>Usage</th><th>Limit</th><th>Entitled</th></tr></thead><tbody>{snapshot.entitlements.map((entitlement) => <tr key={entitlement.feature_key}><td><strong>{entitlement.name}</strong><div className="muted">{entitlement.description}</div></td><td className="mono">{entitlement.metric_key ?? "Unmetered"}</td><td>{Number(entitlement.used_value ?? 0) + Number(entitlement.reserved_value ?? 0)}</td><td>{entitlement.limit_value ?? "No numeric limit"}</td><td><StatusBadge value={entitlement.enabled ? "ACTIVE" : "DISABLED"} /></td></tr>)}</tbody></table></div>
      <div className="panel-body"><div className="alert alert-info">On suspension, expiry or cancellation, records are retained. Authorised read, export, compliance and correction operations continue; licence-expanding administration is denied.</div></div>
    </section>
  </AppShell>;
}
