"use client";

import { useRef, useState } from "react";

export function ExpenseReceiptActions({
  id,
  organisationId,
  status,
  requiresReceipt,
  receiptDocumentId,
  receiptFileName,
  receiptScanStatus,
  receiptStatus,
  availableReceiptDocumentId,
  availableReceiptFileName,
  canManage,
}: {
  id: string;
  organisationId: string;
  status: string;
  requiresReceipt: boolean;
  receiptDocumentId: string | null;
  receiptFileName: string | null;
  receiptScanStatus: string | null;
  receiptStatus: string | null;
  availableReceiptDocumentId: string | null;
  availableReceiptFileName: string | null;
  canManage: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const idempotencyKey = useRef<string | null>(null);

  async function linkReceipt() {
    if (!availableReceiptDocumentId) return;
    idempotencyKey.current ??= crypto.randomUUID();
    setBusy(true);
    setError("");
    try {
      const response = await fetch(`/api/v1/expenses/${encodeURIComponent(id)}/receipt?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": idempotencyKey.current },
        body: JSON.stringify({ schema_version: "1.0.0", receipt_document_id: availableReceiptDocumentId }),
      });
      const body = await response.json() as { detail?: string; errors?: Array<{ message?: string }> };
      if (!response.ok) setError(body.errors?.[0]?.message ?? body.detail ?? "The clean receipt could not be linked.");
      else window.location.reload();
    } catch {
      setError("The platform could not be reached. Retry to reuse the same protected command.");
    } finally {
      setBusy(false);
    }
  }

  if (receiptDocumentId) return <div>
    <strong>{receiptFileName ?? receiptDocumentId}</strong>
    <div className="muted">{receiptScanStatus} / {receiptStatus}</div>
  </div>;

  const uploadHref = `/documents?owner_domain=EXPENSE&owner_resource_id=${encodeURIComponent(id)}`;
  return <div>
    <div className={requiresReceipt ? "warning" : "muted"}>{requiresReceipt ? "Receipt required" : "Receipt optional"}</div>
    {status === "DRAFT" && canManage && availableReceiptDocumentId ? <button className="btn btn-secondary" type="button" disabled={busy} onClick={linkReceipt}>
      {busy ? "Linking…" : `Link ${availableReceiptFileName ?? "clean receipt"}`}
    </button> : null}
    {status === "DRAFT" && canManage && !availableReceiptDocumentId ? <a href={uploadHref}>Upload receipt</a> : null}
    {error ? <div className="field-error" role="alert">{error}</div> : null}
  </div>;
}
