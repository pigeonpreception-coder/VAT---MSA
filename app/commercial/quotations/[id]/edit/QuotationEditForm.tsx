"use client";

import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";

type Option = { id: string; label: string };
export type EditableQuotationLine = {
  key: string;
  product_id: string;
  description: string;
  quantity: string;
  unit_code: string;
  unit_price_cents: string;
  tax_category: "STANDARD" | "ZERO_RATED" | "EXEMPT" | "OUT_OF_SCOPE";
  tax_rate_bps: string;
};

type QuotationValue = {
  id: string;
  quotation_number: string;
  customer_party_id: string;
  issue_date: string;
  valid_until: string;
  notes: string;
};

function messages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The update failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The quotation could not be updated."];
}

export function QuotationEditForm({ organisationId, quotation, initialLines, parties, products }: {
  organisationId: string;
  quotation: QuotationValue;
  initialLines: EditableQuotationLine[];
  parties: Option[];
  products: Option[];
}) {
  const [lines, setLines] = useState(initialLines);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const idempotencyKey = useRef("");

  function updateLine(index: number, patch: Partial<EditableQuotationLine>) {
    setLines((current) => current.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setErrors([]);
    const data = new FormData(event.currentTarget);
    const payload = {
      schema_version: "1.0.0",
      customer_party_id: String(data.get("customer_party_id") ?? ""),
      quotation_number: quotation.quotation_number,
      currency: "NAD",
      issue_date: String(data.get("issue_date") ?? ""),
      valid_until: String(data.get("valid_until") ?? ""),
      notes: String(data.get("notes") ?? "") || undefined,
      lines: lines.map((line) => ({
        ...(line.product_id ? { product_id: line.product_id } : {}),
        description: line.description,
        quantity_micros: Math.round(Number(line.quantity) * 1_000_000),
        unit_code: line.unit_code,
        unit_price_cents: Number(line.unit_price_cents),
        tax_category: line.tax_category,
        tax_rate_bps: Number(line.tax_rate_bps),
      })),
    };
    if (!idempotencyKey.current) idempotencyKey.current = crypto.randomUUID();
    try {
      const response = await fetch(`/api/v1/quotations/${encodeURIComponent(quotation.id)}?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "PATCH",
        headers: { "content-type": "application/json", "idempotency-key": idempotencyKey.current },
        body: JSON.stringify(payload),
      });
      const body = await response.json() as unknown;
      if (!response.ok) setErrors(messages(body));
      else window.location.assign("/commercial?quotation_updated=1");
    } catch {
      setErrors(["The platform could not be reached. Retry without changing the quotation number."]);
    } finally {
      setBusy(false);
    }
  }

  return <form className="panel" onSubmit={submit}>
    <div className="panel-head"><div><h2 className="panel-title">Commercial terms</h2><div className="panel-meta">Every successful save appends a hashed immutable revision</div></div></div>
    <div className="panel-body form-grid">
      {errors.length ? <div className="form-group full alert alert-error" role="alert"><strong>Quotation needs attention</strong>{errors.map((error) => <div key={error}>• {error}</div>)}</div> : null}
      <div className="form-group"><label htmlFor="edit-quotation-number">Quotation number</label><input className="field mono" id="edit-quotation-number" value={quotation.quotation_number} readOnly /><span className="field-help">Immutable after first issue.</span></div>
      <div className="form-group"><label htmlFor="edit-customer">Customer</label><select className="select" id="edit-customer" name="customer_party_id" required defaultValue={quotation.customer_party_id}>{parties.map((party) => <option key={party.id} value={party.id}>{party.label}</option>)}</select></div>
      <div className="form-group"><label htmlFor="edit-issue-date">Issue date</label><input className="field" id="edit-issue-date" name="issue_date" type="date" required defaultValue={quotation.issue_date} /></div>
      <div className="form-group"><label htmlFor="edit-valid-until">Valid until</label><input className="field" id="edit-valid-until" name="valid_until" type="date" required defaultValue={quotation.valid_until} /></div>
      <div className="form-group"><label htmlFor="edit-currency-display">Currency</label><output className="field" id="edit-currency-display">N$</output><span className="field-help">Namibian-dollar presentation; ISO code remains internal.</span></div>
      <div className="form-group"><label htmlFor="edit-notes">Notes</label><textarea className="textarea" id="edit-notes" name="notes" maxLength={2000} defaultValue={quotation.notes} /></div>

      <div className="form-group full"><div className="panel-head"><div><h3 className="panel-title">Quotation lines</h3><div className="panel-meta">Amounts and VAT are recalculated server-side</div></div><button className="btn btn-secondary" type="button" onClick={() => setLines((current) => [...current, { key: `new-${Date.now()}`, product_id: "", description: "", quantity: "1", unit_code: "EA", unit_price_cents: "0", tax_category: "STANDARD", tax_rate_bps: "1500" }])}>Add line</button></div></div>
      {lines.map((line, index) => <fieldset className="form-group full" key={line.key}><legend>Line {index + 1}</legend><div className="form-grid">
        <div className="form-group"><label htmlFor={`edit-product-${index}`}>Catalog product</label><select className="select" id={`edit-product-${index}`} value={line.product_id} onChange={(event) => updateLine(index, { product_id: event.target.value })}><option value="">Custom line</option>{products.map((product) => <option key={product.id} value={product.id}>{product.label}</option>)}</select></div>
        <div className="form-group"><label htmlFor={`edit-description-${index}`}>Description</label><input className="field" id={`edit-description-${index}`} required maxLength={500} value={line.description} onChange={(event) => updateLine(index, { description: event.target.value })} /></div>
        <div className="form-group"><label htmlFor={`edit-quantity-${index}`}>Quantity</label><input className="field" id={`edit-quantity-${index}`} type="number" required min="0.000001" step="0.000001" value={line.quantity} onChange={(event) => updateLine(index, { quantity: event.target.value })} /></div>
        <div className="form-group"><label htmlFor={`edit-unit-${index}`}>Unit</label><input className="field" id={`edit-unit-${index}`} required maxLength={12} value={line.unit_code} onChange={(event) => updateLine(index, { unit_code: event.target.value })} /></div>
        <div className="form-group"><label htmlFor={`edit-price-${index}`}>Unit price (cents)</label><input className="field" id={`edit-price-${index}`} type="number" required min="0" step="1" value={line.unit_price_cents} onChange={(event) => updateLine(index, { unit_price_cents: event.target.value })} /></div>
        <div className="form-group"><label htmlFor={`edit-tax-${index}`}>Tax category</label><select className="select" id={`edit-tax-${index}`} value={line.tax_category} onChange={(event) => { const tax_category = event.target.value as EditableQuotationLine["tax_category"]; updateLine(index, { tax_category, tax_rate_bps: tax_category === "STANDARD" ? line.tax_rate_bps || "1500" : "0" }); }}><option value="STANDARD">Standard</option><option value="ZERO_RATED">Zero rated</option><option value="EXEMPT">Exempt</option><option value="OUT_OF_SCOPE">Out of scope</option></select></div>
        <div className="form-group"><label htmlFor={`edit-rate-${index}`}>Tax rate (basis points)</label><input className="field" id={`edit-rate-${index}`} type="number" required min="0" max="10000" step="1" value={line.tax_rate_bps} onChange={(event) => updateLine(index, { tax_rate_bps: event.target.value })} readOnly={line.tax_category !== "STANDARD"} /></div>
        <div className="form-actions"><button className="btn btn-danger" type="button" disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))}>Remove line</button></div>
      </div></fieldset>)}
      <div className="form-actions full"><button className="btn btn-primary" disabled={busy}>{busy ? "Saving revision…" : "Save quotation revision"}</button><Link className="btn btn-secondary" href="/commercial">Cancel</Link></div>
    </div>
  </form>;
}
