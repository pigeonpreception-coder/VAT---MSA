"use client";

import { useState } from "react";

export function QuotationActions({ id, organisationId, status }: { id: string; organisationId: string; status: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  if (status !== "ISSUED") return null;
  async function accept() {
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(id)}/accept?organisation_id=${encodeURIComponent(organisationId)}`, { method: "POST", headers: { "idempotency-key": crypto.randomUUID() } });
      if (!response.ok) {
        const body = await response.json() as { detail?: string };
        setError(body.detail ?? "Acceptance failed.");
      } else window.location.reload();
    } catch {
      setError("The platform could not be reached.");
    } finally {
      setBusy(false);
    }
  }
  return <div><button className="btn btn-secondary" type="button" onClick={accept} disabled={busy}>{busy ? "Accepting…" : "Accept"}</button>{error ? <div className="field-error">{error}</div> : null}</div>;
}
