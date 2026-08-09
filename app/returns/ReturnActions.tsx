"use client";

import { useState } from "react";

type Command = "generate" | "request-approval" | "submit" | "approve" | "reject";

export function ReturnActions({ periodId, versionId, status, approvalTaskId }: { periodId?: string; versionId?: string | null; status?: string | null; approvalTaskId?: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function run(command: Command) {
    setBusy(true);
    setError("");
    let url = "";
    let body: string | undefined;
    if (command === "generate") url = `/api/v1/vat-periods/${encodeURIComponent(periodId ?? "")}/returns`;
    else if (command === "request-approval") url = `/api/v1/vat-returns/${encodeURIComponent(versionId ?? "")}/approval-requests`;
    else if (command === "submit") url = `/api/v1/vat-returns/${encodeURIComponent(versionId ?? "")}/submissions`;
    else {
      const comment = window.prompt(`${command === "approve" ? "Approval" : "Rejection"} comment (required):`);
      if (!comment) { setBusy(false); return; }
      url = `/api/v1/approval-tasks/${encodeURIComponent(approvalTaskId ?? "")}/decision`;
      body = JSON.stringify({ decision: command === "approve" ? "APPROVE" : "REJECT", comment });
    }
    try {
      const response = await fetch(url, { method: "POST", headers: { "idempotency-key": crypto.randomUUID(), ...(body ? { "content-type": "application/json" } : {}) }, ...(body ? { body } : {}) });
      if (!response.ok) {
        const result = await response.json() as { detail?: string };
        setError(result.detail ?? "The VAT command failed.");
      } else window.location.reload();
    } catch {
      setError("The VAT service could not be reached.");
    } finally {
      setBusy(false);
    }
  }

  if (approvalTaskId) return <div className="actions"><button className="btn btn-primary" type="button" disabled={busy} onClick={() => run("approve")}>Approve</button><button className="btn btn-secondary" type="button" disabled={busy} onClick={() => run("reject")}>Reject</button>{error ? <div className="field-error">{error}</div> : null}</div>;
  let command: Command | null = null;
  let label = "";
  if (!versionId && status === "OPEN") { command = "generate"; label = "Generate return"; }
  else if (versionId && status === "DRAFT") { command = "request-approval"; label = "Request approval"; }
  else if (versionId && status === "APPROVED") { command = "submit"; label = "Request ITAS submission"; }
  if (!command) return null;
  return <div><button className="btn btn-secondary" type="button" disabled={busy} onClick={() => run(command)}>{busy ? "Working…" : label}</button>{error ? <div className="field-error">{error}</div> : null}</div>;
}
