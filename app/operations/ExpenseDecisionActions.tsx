"use client";

import { useRef, useState } from "react";

type ExpenseDecision = "APPROVE" | "REJECT";

export function ExpenseDecisionActions({ id, organisationId, status, createdBy, actorId, canDecide, receiptRequired, receiptReady }: {
  id: string;
  organisationId: string;
  status: string;
  createdBy: string;
  actorId: string;
  canDecide: boolean;
  receiptRequired: boolean;
  receiptReady: boolean;
}) {
  const [busy, setBusy] = useState<ExpenseDecision | null>(null);
  const [error, setError] = useState("");
  const idempotency = useRef<{ signature: string; key: string } | null>(null);

  function commandKey(signature: string) {
    if (idempotency.current?.signature !== signature) idempotency.current = { signature, key: crypto.randomUUID() };
    return idempotency.current.key;
  }

  async function decide(decision: ExpenseDecision) {
    const verb = decision === "APPROVE" ? "approval" : "rejection";
    const reason = window.prompt(`Record the independent ${verb} reason.`)?.trim();
    if (!reason) return;
    setBusy(decision);
    setError("");
    const payload = { schema_version: "1.0.0", decision, reason };
    const signature = JSON.stringify(payload);
    try {
      const response = await fetch(`/api/v1/expenses/${encodeURIComponent(id)}/decision?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": commandKey(signature) },
        body: signature,
      });
      const body = await response.json() as { detail?: string; errors?: Array<{ message?: string }> };
      if (!response.ok) setError(body.errors?.[0]?.message ?? body.detail ?? "The expense decision failed.");
      else window.location.reload();
    } catch {
      setError("The platform could not be reached. Retry the same decision and reason.");
    } finally {
      setBusy(null);
    }
  }

  if (status !== "DRAFT") return <span className="muted">Decision recorded</span>;
  if (!canDecide) return <span className="muted">Awaiting authorised reviewer</span>;
  if (createdBy === actorId) return <span className="muted">Independent reviewer required</span>;
  return <div>
    <div className="actions">
      <button className="btn btn-primary" type="button" disabled={busy !== null || (receiptRequired && !receiptReady)} onClick={() => decide("APPROVE")}>{busy === "APPROVE" ? "Approving…" : "Approve"}</button>
      <button className="btn btn-danger" type="button" disabled={busy !== null} onClick={() => decide("REJECT")}>{busy === "REJECT" ? "Rejecting…" : "Reject"}</button>
    </div>
    {receiptRequired && !receiptReady ? <div className="field-help">Approval is locked until a clean, available receipt is linked.</div> : null}
    {error ? <div className="field-error" role="alert">{error}</div> : null}
  </div>;
}
