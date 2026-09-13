import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { hasPermission } from "@/lib/auth";
import { formatDateTime } from "@/lib/format";
import { InviteEmployeeForm, TerminateEmployeeButton } from "./HumanResourcesActions";

export const metadata: Metadata = { title: "Human resources" };
export const dynamic = "force-dynamic";

export default async function HumanResourcesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "employees:read", { operationClass: "READ" });
  const snapshot = await getAdministrationSnapshot(user);
  const employees = snapshot.employees as Array<Record<string, string | null>>;
  const active = employees.filter((employee) => employee.status === "ACTIVE").length;
  const invited = employees.filter((employee) => employee.status === "INVITED").length;
  const terminated = employees.filter((employee) => employee.status === "TERMINATED").length;
  const canManage = hasPermission(user, "employees:manage");

  return <AppShell active="human-resources" permission="employees:read">
    <PageHeader eyebrow="Operations" title="Human Resources Module" description="Employee directory, controlled onboarding invitations and offboarding. Linking an invited employee to a login identity remains an Administration action." />
    <section className="metric-grid">
      <article className="metric"><div className="metric-top"><span className="metric-label">Active employees</span><span className="metric-icon">A</span></div><div className="metric-value">{active}</div><div className="metric-foot">Currently employed</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Invited</span><span className="metric-icon">I</span></div><div className="metric-value">{invited}</div><div className="metric-foot">Awaiting identity activation</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Terminated</span><span className="metric-icon">T</span></div><div className="metric-value">{terminated}</div><div className="metric-foot">Historical records preserved</div></article>
      <article className="metric"><div className="metric-top"><span className="metric-label">Departments</span><span className="metric-icon">D</span></div><div className="metric-value">{Number(snapshot.structures.departments ?? 0)}</div><div className="metric-foot">{Number(snapshot.structures.branches ?? 0)} branches · {Number(snapshot.structures.job_titles ?? 0)} job titles</div></article>
    </section>
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Employee directory</h2><div className="panel-meta">Employment structure and status</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Employee</th><th>Employment</th><th>Last activity</th><th>Status</th>{canManage ? <th>Action</th> : null}</tr></thead><tbody>
        {employees.map((employee) => <tr key={String(employee.id)}>
          <td><strong>{String(employee.full_name)}</strong><div className="muted mono">{String(employee.employee_number)} · {String(employee.email)}</div></td>
          <td>{String(employee.job_title ?? "Unassigned")}<div className="muted">{String(employee.department ?? "No department")} · {String(employee.branch ?? "No branch")}</div></td>
          <td>{employee.last_activity_at ? formatDateTime(String(employee.last_activity_at)) : "Not yet active"}</td>
          <td><StatusBadge value={String(employee.status)} /></td>
          {canManage ? <td>{employee.status !== "TERMINATED" ? <TerminateEmployeeButton employeeId={String(employee.id)} employeeName={String(employee.full_name)} /> : <span className="muted">Read-only history</span>}</td> : null}
        </tr>)}
        {!employees.length ? <tr><td colSpan={canManage ? 5 : 4} className="muted">No employees have been recorded.</td></tr> : null}
      </tbody></table></div>
    </section>
    {canManage ? <InviteEmployeeForm /> : null}
  </AppShell>;
}
