"use client";

import Link from "next/link";
import { type FormEvent, useRef, useState } from "react";

export function QuotationActions({
  id,
  organisationId,
  status,
  issueDate,
  validUntil,
  convertedInvoiceId,
  canManage,
}: {
  id: string;
  organisationId: string;
  status: string;
  issueDate: string;
  validUntil: string;
  convertedInvoiceId?: string | null;
  canManage: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const idempotencyKey = useRef("");
  function commandKey() {
    if (!idempotencyKey.current) idempotencyKey.current = crypto.randomUUID();
    return idempotencyKey.current;
  }
  async function accept() {
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(id)}/accept?organisation_id=${encodeURIComponent(organisationId)}`, { method: "POST", headers: { "idempotency-key": commandKey() } });
      if (!response.ok) {
        const body = await response.json() as { detail?: string };
        setError(body.detail ?? "Acceptance failed.");
      } else window.location.reload();
    } catch {
      setError("The platform could not be reached. Retry the same action.");
    } finally {
      setBusy(false);
    }
  }
  async function reject() {
    const reason = window.prompt("Record the customer's quotation rejection reason.")?.trim();
    if (!reason) return;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(id)}/rejection?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": commandKey() },
        body: JSON.stringify({ schema_version: "1.0.0", reason }),
      });
      if (!response.ok) {
        const body = await response.json() as { detail?: string };
        setError(body.detail ?? "Rejection failed.");
      } else window.location.reload();
    } catch {
      setError("The platform could not be reached. Retry the same rejection.");
    } finally {
      setBusy(false);
    }
  }
  async function expire() {
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(id)}/expiration?organisation_id=${encodeURIComponent(organisationId)}`, { method: "POST", headers: { "idempotency-key": commandKey() } });
      if (!response.ok) {
        const body = await response.json() as { detail?: string };
        setError(body.detail ?? "Expiry failed.");
      } else window.location.reload();
    } catch {
      setError("The platform could not be reached. Retry the same expiry action.");
    } finally {
      setBusy(false);
    }
  }
  async function convert(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    const form = new FormData(event.currentTarget);
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(id)}/convert?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": commandKey() },
        body: JSON.stringify({ schema_version: "1.0.0", invoice_number: String(form.get("invoice_number") ?? ""), issue_date: String(form.get("issue_date") ?? "") }),
      });
      const body = await response.json() as { resource?: { id?: string }; detail?: string };
      if (!response.ok || !body.resource?.id) setError(body.detail ?? "Conversion failed.");
      else window.location.assign(`/invoices/${encodeURIComponent(body.resource.id)}?created=1`);
    } catch {
      setError("The platform could not be reached. Retry without changing the invoice number.");
    } finally {
      setBusy(false);
    }
  }
  if (status === "CONVERTED" && convertedInvoiceId) return <Link className="btn btn-secondary" href={`/invoices/${convertedInvoiceId}`}>View invoice</Link>;
  if (!canManage) return <span className="muted">Read only</span>;
  if (status === "ISSUED") {
    const overdue = validUntil < new Date().toISOString().slice(0, 10);
    return <div><div className="actions">{overdue
      ? <button className="btn btn-secondary" type="button" onClick={expire} disabled={busy}>{busy ? "Expiring…" : "Expire"}</button>
      : <><button className="btn btn-secondary" type="button" onClick={accept} disabled={busy}>{busy ? "Accepting…" : "Accept"}</button><Link className="btn btn-secondary" href={`/commercial/quotations/${encodeURIComponent(id)}/edit`}>Edit</Link><button className="btn btn-danger" type="button" onClick={reject} disabled={busy}>Reject</button></>}
    </div>{error ? <div className="field-error">{error}</div> : null}</div>;
  }
  if (status === "ACCEPTED") return <form onSubmit={convert} className="quotation-conversion">
    <input className="field mono" name="invoice_number" aria-label="Invoice number" required minLength={2} maxLength={100} placeholder="INV-2026-0001" />
    <input className="field" name="issue_date" aria-label="Invoice issue date" type="date" required min={issueDate} defaultValue={new Date().toISOString().slice(0, 10)} />
    <button className="btn btn-primary" type="submit" disabled={busy}>{busy ? "Converting..." : "Convert"}</button>
    {error ? <div className="field-error">{error}</div> : null}
  </form>;
  return null;
}
