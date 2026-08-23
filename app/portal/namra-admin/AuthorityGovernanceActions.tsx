"use client";

import { useState } from "react";
import { StatusBadge } from "@/components/PageHeader";

export type AuthorityOnboardingRow = {
  id: string;
  authority_name: string;
  target_environment: string;
  status: string;
  purpose: string;
  requester_name: string;
  submitted_at: string;
  decision_type: string | null;
  decision_reason: string | null;
  decided_by_name: string | null;
};

type ActionState = { kind: "idle" | "working" | "success" | "error"; message: string };

export function AuthorityGovernanceActions({ cases }: { cases: AuthorityOnboardingRow[] }) {
  const [stepUpConfirmed, setStepUpConfirmed] = useState(false);
  const [state, setState] = useState<ActionState>({ kind: "idle", message: "" });

  async function decide(item: AuthorityOnboardingRow, decision: "APPROVE_LOCAL_STAGING" | "REJECT") {
    if (!stepUpConfirmed) {
      setState({ kind: "error", message: "Confirm the local/staging privileged-change step-up check before recording a decision." });
      return;
    }
    const prompt = decision === "APPROVE_LOCAL_STAGING"
      ? "Record the independent local-staging approval reason. This has no production activation effect."
      : "Record the authority-onboarding rejection reason.";
    const reason = window.prompt(prompt)?.trim();
    if (!reason) return;
    setState({ kind: "working", message: "Recording the immutable independent authority-governance decision…" });
    try {
      const response = await fetch(`/api/v1/tax-authority-onboarding-cases/${encodeURIComponent(item.id)}/decisions`, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": crypto.randomUUID(),
          "x-vat-msa-local-step-up": "confirmed",
        },
        body: JSON.stringify({ schema_version: "1.0.0", decision, reason }),
      });
      const body = await response.json() as { detail?: string };
      if (!response.ok) throw new Error(body.detail ?? "The protected authority-governance decision was rejected.");
      setState({
        kind: "success",
        message: decision === "APPROVE_LOCAL_STAGING"
          ? "Local-staging readiness approved independently. Live federation and production activation remain disabled."
          : "Authority onboarding rejected with immutable evidence.",
      });
      setStepUpConfirmed(false);
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "The authority-governance decision failed." });
    }
  }

  return <section className="panel" style={{ marginTop: 20 }}>
    <div className="panel-head"><div><h2 className="panel-title">Authority onboarding control board</h2><div className="panel-meta">Independent local decisions only; production activation is unavailable</div></div></div>
    <div className="table-wrap"><table><thead><tr><th>Authority</th><th>Environment</th><th>Purpose</th><th>Requester</th><th>Status</th><th>Decision</th></tr></thead><tbody>
      {cases.map((item) => <tr key={item.id}>
        <td><strong>{item.authority_name}</strong><div className="mono muted">{item.id}</div></td>
        <td><StatusBadge value={item.target_environment} /></td>
        <td>{item.purpose}<div className="muted">Submitted {item.submitted_at}</div></td>
        <td>{item.requester_name}</td>
        <td><StatusBadge value={item.status} />{item.decided_by_name ? <div className="muted">{item.decision_type} · {item.decided_by_name}</div> : null}</td>
        <td>{item.status === "SUBMITTED" && item.target_environment === "LOCAL_STAGING" ? <div className="inline-actions">
          <button className="btn btn-primary" type="button" disabled={state.kind === "working"} onClick={() => decide(item, "APPROVE_LOCAL_STAGING")}>Approve local staging</button>
          <button className="btn btn-secondary" type="button" disabled={state.kind === "working"} onClick={() => decide(item, "REJECT")}>Reject</button>
        </div> : <span className="muted">No local action available</span>}</td>
      </tr>)}
    </tbody></table></div>
    <div className="panel-body">
      <label className="step-up-check"><input type="checkbox" checked={stepUpConfirmed} onChange={(event) => setStepUpConfirmed(event.target.checked)} /> I completed the local/staging privileged-change step-up check.</label>
      {state.message ? <div className={`alert ${state.kind === "error" ? "alert-error" : state.kind === "success" ? "alert-success" : "alert-info"}`} style={{ marginTop: 12 }}>{state.message}</div> : null}
    </div>
  </section>;
}
