"use client";

import { useState } from "react";

type Option = { id: string; label: string };

function messages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The command failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The command could not be completed."];
}

export function QuotationForm({ organisationId, parties, products }: { organisationId: string; parties: Option[]; products: Option[] }) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [accepted, setAccepted] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setErrors([]);
    setAccepted(null);
    const form = new FormData(event.currentTarget);
    const payload = {
      schema_version: "1.0.0",
      customer_party_id: String(form.get("customer_party_id") ?? ""),
      quotation_number: String(form.get("quotation_number") ?? ""),
      currency: "NAD",
      issue_date: String(form.get("issue_date") ?? ""),
      valid_until: String(form.get("valid_until") ?? ""),
      notes: String(form.get("notes") ?? "") || undefined,
      lines: [{
        product_id: String(form.get("product_id") ?? "") || undefined,
        description: String(form.get("description") ?? ""),
        quantity_micros: Number(form.get("quantity")) * 1_000_000,
        unit_code: String(form.get("unit_code") ?? "EA"),
        unit_price_cents: Number(form.get("unit_price_cents")),
        tax_category: "STANDARD",
        tax_rate_bps: 1500,
      }],
    };
    try {
      const response = await fetch(`/api/v1/quotations?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify(payload),
      });
      const body = await response.json() as { resource?: { id?: string } };
      if (!response.ok) setErrors(messages(body));
      else {
        setAccepted(String(body.resource?.id ?? "created"));
        event.currentTarget.reset();
        window.setTimeout(() => window.location.reload(), 700);
      }
    } catch {
      setErrors(["The platform could not be reached. Retry without changing the quotation number."]);
    } finally {
      setBusy(false);
    }
  }

  return <form className="form-grid" onSubmit={submit}>
    {errors.length ? <div className="form-group full alert alert-error" role="alert"><strong>Quotation needs attention</strong>{errors.map((error) => <div key={error}>• {error}</div>)}</div> : null}
    {accepted ? <div className="form-group full alert alert-success" role="status"><strong>Quotation issued</strong><br /><span className="mono">{accepted}</span></div> : null}
    <div className="form-group"><label htmlFor="quotation_number">Quotation number</label><input className="field" id="quotation_number" name="quotation_number" required maxLength={40} placeholder="QUO-2026-0002" /></div>
    <div className="form-group"><label htmlFor="customer_party_id">Customer</label><select className="select" id="customer_party_id" name="customer_party_id" required defaultValue=""><option value="" disabled>Select customer</option>{parties.map((party) => <option key={party.id} value={party.id}>{party.label}</option>)}</select></div>
    <div className="form-group"><label htmlFor="issue_date">Issue date</label><input className="field" type="date" id="issue_date" name="issue_date" required /></div>
    <div className="form-group"><label htmlFor="valid_until">Valid until</label><input className="field" type="date" id="valid_until" name="valid_until" required /></div>
    <div className="form-group"><label htmlFor="product_id">Catalog product</label><select className="select" id="product_id" name="product_id" defaultValue=""><option value="">Custom line</option>{products.map((product) => <option key={product.id} value={product.id}>{product.label}</option>)}</select></div>
    <div className="form-group"><label htmlFor="description">Line description</label><input className="field" id="description" name="description" required maxLength={500} /></div>
    <div className="form-group"><label htmlFor="quantity">Quantity</label><input className="field" type="number" id="quantity" name="quantity" required min="1" step="1" defaultValue="1" /></div>
    <div className="form-group"><label htmlFor="unit_price_cents">Unit price (cents)</label><input className="field" type="number" id="unit_price_cents" name="unit_price_cents" required min="0" step="1" /><span className="field-help">Enter N$ 100.00 as 10000. VAT is calculated server-side.</span></div>
    <div className="form-group"><label htmlFor="unit_code">Unit code</label><input className="field" id="unit_code" name="unit_code" required maxLength={12} defaultValue="EA" /></div>
    <div className="form-group"><label htmlFor="notes">Notes</label><input className="field" id="notes" name="notes" maxLength={2000} /></div>
    <div className="form-group full form-actions"><button className="btn btn-primary" type="submit" disabled={busy || parties.length === 0}>{busy ? "Issuing…" : "Issue quotation"}</button></div>
  </form>;
}
