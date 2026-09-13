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

export function InviteEmployeeForm() {
  const [state, setState] = useState<ActionState>({ kind: "idle", message: "" });
  const [stepUp, setStepUp] = useState(false);

  const invite = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setState({ kind: "working", message: "Recording controlled invitation…" });
    try {
      await privilegedPost("/api/v1/organisations/employees", {
        employee_number: data.get("employee_number"), full_name: data.get("full_name"), email: data.get("email"),
      }, stepUp);
      setState({ kind: "success", message: "Invitation recorded. External email delivery remains disabled in local staging." });
      form.reset();
      setStepUp(false);
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) { setState({ kind: "error", message: error instanceof Error ? error.message : "Invitation failed." }); }
  };

  return <form className="panel" onSubmit={invite}>
    <div className="panel-head"><div><h2 className="panel-title">Invite employee</h2><div className="panel-meta">Seat limit checked atomically; no email is sent</div></div></div>
    <div className="panel-body form-grid">
      <div className="form-group"><label htmlFor="hr-employee-number">Employee number</label><input className="field" id="hr-employee-number" name="employee_number" required maxLength={40} placeholder="EMP-004" /></div>
      <div className="form-group"><label htmlFor="hr-employee-name">Full name</label><input className="field" id="hr-employee-name" name="full_name" required maxLength={120} placeholder="Synthetic Test User" /></div>
      <div className="form-group full"><label htmlFor="hr-employee-email">Email</label><input className="field" id="hr-employee-email" name="email" required type="email" placeholder="synthetic.user@example.test" /></div>
      <label className="step-up-check full"><input type="checkbox" checked={stepUp} onChange={(event) => setStepUp(event.target.checked)} /> I completed the local/staging privileged-change step-up check.</label>
      <div className="form-actions full"><button className="btn btn-primary" disabled={state.kind === "working"}>Record invitation</button></div>
      {state.message ? <div className={`alert full ${state.kind === "error" ? "alert-error" : state.kind === "success" ? "alert-success" : "alert-info"}`}>{state.message}</div> : null}
    </div>
  </form>;
}

export function TerminateEmployeeButton({ employeeId, employeeName }: { employeeId: string; employeeName: string }) {
  const [working, setWorking] = useState(false);

  const terminate = async () => {
    const reason = window.prompt(`Why should ${employeeName} be terminated? Historical records will be preserved.`)?.trim();
    if (!reason) return;
    const stepUpConfirmed = window.confirm("Confirm you completed the local/staging privileged-change step-up check before terminating this employee.");
    if (!stepUpConfirmed) return;
    setWorking(true);
    try {
      await privilegedPost(`/api/v1/organisations/employees/${encodeURIComponent(employeeId)}/termination`, { reason }, stepUpConfirmed);
      window.location.reload();
    } catch (error) {
      window.alert(error instanceof Error ? error.message : "Termination failed.");
      setWorking(false);
    }
  };

  return <button className="btn btn-danger" type="button" onClick={terminate} disabled={working}>Terminate</button>;
}
