"use client";

import { useState, type FormEvent } from "react";

type ActionState = { kind: "idle" | "working" | "success" | "error"; message: string };

async function privilegedPost(path: string, payload: unknown, stepUpConfirmed: boolean) {
  if (!stepUpConfirmed) throw new Error("Confirm the local/staging step-up check before submitting this privileged change.");
  const response = await fetch(path, {
    method: "POST",
    headers: { "content-type": "application/json", "x-vat-msa-local-step-up": "confirmed" },
    body: JSON.stringify(payload),
  });
  const body = await response.json() as { detail?: string };
  if (!response.ok) throw new Error(body.detail ?? "The protected operation was rejected.");
  return body;
}

export function AdministrationActions() {
  const [employeeState, setEmployeeState] = useState<ActionState>({ kind: "idle", message: "" });
  const [roleState, setRoleState] = useState<ActionState>({ kind: "idle", message: "" });
  const [employeeStepUp, setEmployeeStepUp] = useState(false);
  const [roleStepUp, setRoleStepUp] = useState(false);

  const invite = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setEmployeeState({ kind: "working", message: "Recording controlled invitation…" });
    try {
      await privilegedPost("/api/v1/organisations/employees", {
        employee_number: data.get("employee_number"), full_name: data.get("full_name"), email: data.get("email"),
      }, employeeStepUp);
      setEmployeeState({ kind: "success", message: "Invitation recorded. External email delivery remains disabled in local staging." });
      form.reset();
      setEmployeeStepUp(false);
    } catch (error) { setEmployeeState({ kind: "error", message: error instanceof Error ? error.message : "Invitation failed." }); }
  };

  const createRole = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setRoleState({ kind: "working", message: "Creating least-privilege role…" });
    try {
      await privilegedPost("/api/v1/organisations/roles", {
        name: data.get("name"), description: data.get("description"),
        permissions: String(data.get("permissions") ?? "").split(",").map((item) => item.trim()).filter(Boolean),
      }, roleStepUp);
      setRoleState({ kind: "success", message: "Role created from the approved permission catalogue." });
      form.reset();
      setRoleStepUp(false);
    } catch (error) { setRoleState({ kind: "error", message: error instanceof Error ? error.message : "Role creation failed." }); }
  };

  return <div className="admin-actions-grid">
    <form className="panel admin-action" onSubmit={invite}>
      <div className="panel-head"><div><h2 className="panel-title">Invite employee</h2><div className="panel-meta">Seat limit checked atomically; no email is sent</div></div></div>
      <div className="panel-body form-grid">
        <div className="form-group"><label htmlFor="employee-number">Employee number</label><input className="field" id="employee-number" name="employee_number" required maxLength={40} placeholder="EMP-004" /></div>
        <div className="form-group"><label htmlFor="employee-name">Full name</label><input className="field" id="employee-name" name="full_name" required maxLength={120} placeholder="Synthetic Test User" /></div>
        <div className="form-group full"><label htmlFor="employee-email">Email</label><input className="field" id="employee-email" name="email" required type="email" placeholder="synthetic.user@example.test" /></div>
        <label className="step-up-check full"><input type="checkbox" checked={employeeStepUp} onChange={(event) => setEmployeeStepUp(event.target.checked)} /> I completed the local/staging privileged-change step-up check.</label>
        <div className="form-actions full"><button className="btn btn-primary" disabled={employeeState.kind === "working"}>Record invitation</button></div>
        {employeeState.message ? <div className={`alert full ${employeeState.kind === "error" ? "alert-error" : employeeState.kind === "success" ? "alert-success" : "alert-info"}`}>{employeeState.message}</div> : null}
      </div>
    </form>

    <form className="panel admin-action" onSubmit={createRole}>
      <div className="panel-head"><div><h2 className="panel-title">Create organisation role</h2><div className="panel-meta">Protected platform and statutory permissions are excluded</div></div></div>
      <div className="panel-body form-grid">
        <div className="form-group"><label htmlFor="role-name">Role name</label><input className="field" id="role-name" name="name" required maxLength={80} placeholder="Branch VAT Reviewer" /></div>
        <div className="form-group"><label htmlFor="role-description">Description</label><input className="field" id="role-description" name="description" required maxLength={240} placeholder="Reviews branch VAT evidence" /></div>
        <div className="form-group full"><label htmlFor="role-permissions">Permission codes</label><input className="field" id="role-permissions" name="permissions" required placeholder="invoices:read, returns:read" /><span className="field-help">Comma-separated entries from the approved access catalogue.</span></div>
        <label className="step-up-check full"><input type="checkbox" checked={roleStepUp} onChange={(event) => setRoleStepUp(event.target.checked)} /> I completed the local/staging privileged-change step-up check.</label>
        <div className="form-actions full"><button className="btn btn-primary" disabled={roleState.kind === "working"}>Create role</button></div>
        {roleState.message ? <div className={`alert full ${roleState.kind === "error" ? "alert-error" : roleState.kind === "success" ? "alert-success" : "alert-info"}`}>{roleState.message}</div> : null}
      </div>
    </form>
  </div>;
}
