"use client";

import { useState } from "react";

export function DocumentUploadForm({ defaultOwnerDomain = "", defaultOwnerResourceId = "" }: { defaultOwnerDomain?: string; defaultOwnerResourceId?: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ id: string; checksum_sha256: string } | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setResult(null);
    const form = new FormData(event.currentTarget);
    try {
      const response = await fetch("/api/v1/documents", { method: "POST", body: form });
      const body = await response.json() as { document?: { id: string; checksum_sha256: string }; detail?: string };
      if (!response.ok) setError(body.detail ?? "The evidence file was not accepted.");
      else {
        setResult(body.document ?? null);
        event.currentTarget.reset();
        window.setTimeout(() => window.location.reload(), 900);
      }
    } catch {
      setError("The document service could not be reached.");
    } finally {
      setBusy(false);
    }
  }

  return <form className="form-grid" onSubmit={submit}>
    <div className="form-group"><label htmlFor="owner_domain">Evidence domain</label><select className="select" id="owner_domain" name="owner_domain" required defaultValue={defaultOwnerDomain}><option value="" disabled>Select domain</option><option value="EXPENSE">Expense</option><option value="IMPORT">Import</option><option value="AUDIT_CASE">Audit case</option><option value="VAT_ADJUSTMENT">VAT adjustment</option><option value="REFUND">Refund</option><option value="BANK_IMPORT">Bank import</option></select></div>
    <div className="form-group"><label htmlFor="owner_resource_id">Owner resource ID</label><input className="field" id="owner_resource_id" name="owner_resource_id" required minLength={2} maxLength={100} placeholder="Resource identifier" defaultValue={defaultOwnerResourceId} /></div>
    <div className="form-group"><label htmlFor="classification">Classification</label><select className="select" id="classification" name="classification" required defaultValue="TAX_CONFIDENTIAL"><option value="INTERNAL">Internal</option><option value="CONFIDENTIAL">Confidential</option><option value="TAX_CONFIDENTIAL">Tax confidential</option><option value="RESTRICTED">Restricted</option></select></div>
    <div className="form-group"><label htmlFor="file">Evidence file</label><input className="field" type="file" id="file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.csv,.xlsx" /><span className="field-help">PDF, PNG, JPEG, CSV or XLSX; maximum 10 MiB.</span></div>
    {error ? <div className="form-group full alert alert-error" role="alert"><strong>Upload rejected</strong><br />{error}</div> : null}
    {result ? <div className="form-group full alert alert-success" role="status"><strong>Evidence quarantined</strong><br /><span className="mono">{result.id}<br />SHA-256 {result.checksum_sha256}</span></div> : null}
    <div className="form-group full form-actions"><button className="btn btn-primary" type="submit" disabled={busy}>{busy ? "Quarantining..." : "Upload to quarantine"}</button></div>
  </form>;
}
