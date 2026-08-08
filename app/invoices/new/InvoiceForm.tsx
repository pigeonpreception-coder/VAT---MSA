"use client";

import { useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { formatMoney } from "@/lib/format";

type TaxpayerOption = { id: string; legalName: string; vatNumber: string };
type EditableLine = { id: string; description: string; quantity: string; unitPrice: string; category: "STANDARD" | "ZERO_RATED" | "EXEMPT"; rate: string };

const newLine = (): EditableLine => ({ id: crypto.randomUUID(), description: "", quantity: "1", unitPrice: "0.00", category: "STANDARD", rate: "15.00" });
const amount = (value: number) => (Number.isFinite(value) ? value : 0);

export function InvoiceForm({ taxpayers }: { taxpayers: TaxpayerOption[] }) {
  const router = useRouter();
  const idempotencyKey = useRef(`portal-${crypto.randomUUID()}`);
  const [supplierVat, setSupplierVat] = useState(taxpayers[0]?.vatNumber ?? "");
  const [customerVat, setCustomerVat] = useState(taxpayers[1]?.vatNumber ?? "");
  const [customerName, setCustomerName] = useState(taxpayers[1]?.legalName ?? "");
  const [invoiceNumber, setInvoiceNumber] = useState(`INV-${new Date().getUTCFullYear()}-`);
  const [sourceDocumentId, setSourceDocumentId] = useState(`PORTAL-${crypto.randomUUID().slice(0, 8).toUpperCase()}`);
  const [issueDate, setIssueDate] = useState(new Date().toISOString().slice(0, 10));
  const [documentType, setDocumentType] = useState("TAX_INVOICE");
  const [lines, setLines] = useState<EditableLine[]>([newLine()]);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);

  const totals = useMemo(() => lines.reduce((current, line) => {
    const net = amount(Number(line.quantity) * Number(line.unitPrice));
    const tax = amount(net * Number(line.rate) / 100);
    return { net: current.net + net, tax: current.tax + tax, total: current.total + net + tax };
  }, { net: 0, tax: 0, total: 0 }), [lines]);

  function updateLine(id: string, field: keyof EditableLine, value: string) {
    setLines((current) => current.map((line) => {
      if (line.id !== id) return line;
      if (field === "category") return { ...line, category: value as EditableLine["category"], rate: value === "STANDARD" ? "15.00" : "0.00" };
      return { ...line, [field]: value };
    }));
  }

  function chooseCustomer(vatNumber: string) {
    setCustomerVat(vatNumber);
    const taxpayer = taxpayers.find((item) => item.vatNumber === vatNumber);
    if (taxpayer) setCustomerName(taxpayer.legalName);
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true); setErrors([]);
    const supplier = taxpayers.find((item) => item.vatNumber === supplierVat);
    const payload = {
      schema_version: "1.0.0",
      document_type: documentType,
      source: { system_id: "VAT-MSA-PORTAL", document_id: sourceDocumentId, submitted_at: new Date().toISOString() },
      supplier: { name: supplier?.legalName ?? "", identifiers: [{ type: "VAT_NUMBER", value: supplierVat, country: "NA" }] },
      customer: { name: customerName || "Walk-in customer", identifiers: customerVat ? [{ type: "VAT_NUMBER", value: customerVat, country: "NA" }] : [{ type: "OTHER", value: "CONSUMER", country: "NA" }] },
      invoice_number: invoiceNumber,
      issue_date: issueDate,
      currency: "NAD",
      lines: lines.map((line, index) => {
        const net = amount(Number(line.quantity) * Number(line.unitPrice));
        const tax = amount(net * Number(line.rate) / 100);
        return {
          line_number: index + 1, description: line.description, quantity: line.quantity, unit_code: "EA",
          unit_price: Number(line.unitPrice).toFixed(2), net_amount: net.toFixed(2),
          tax: { category: line.category, rate: Number(line.rate).toFixed(2), taxable_amount: net.toFixed(2), tax_amount: tax.toFixed(2), rule_reference: "NA-VAT-PILOT-2026.1" },
        };
      }),
      totals: {
        line_net_amount: totals.net.toFixed(2), tax_exclusive_amount: totals.net.toFixed(2), tax_amount: totals.tax.toFixed(2),
        tax_inclusive_amount: totals.total.toFixed(2), payable_amount: totals.total.toFixed(2),
      },
    };

    try {
      const response = await fetch("/api/v1/invoices", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": idempotencyKey.current }, body: JSON.stringify(payload) });
      const result = await response.json() as { invoice_id?: string; detail?: string; errors?: Array<{ message: string }> };
      if (!response.ok || !result.invoice_id) {
        setErrors(result.errors?.map((error) => error.message) ?? [result.detail ?? "The invoice could not be submitted."]);
        return;
      }
      router.push(`/invoices/${result.invoice_id}?created=1`);
      router.refresh();
    } catch {
      setErrors(["The platform could not be reached. Check the connection and retry with the same submission."]);
    } finally { setSubmitting(false); }
  }

  return <form className="panel" onSubmit={submit}>
    <div className="panel-head"><div><h2 className="panel-title">Canonical fiscal document</h2><div className="panel-meta">Required fields follow schema version 1.0.0</div></div><span className="status status-processing">Draft</span></div>
    <div className="panel-body form-grid">
      {errors.length ? <div className="form-group full alert alert-error" role="alert"><strong>Submission needs attention</strong>{errors.map((error) => <div key={error}>• {error}</div>)}</div> : null}
      <h3 className="section-title">Document identity</h3>
      <div className="form-group"><label htmlFor="document-type">Document type</label><select id="document-type" className="select" value={documentType} onChange={(event) => setDocumentType(event.target.value)}><option value="TAX_INVOICE">Tax invoice</option><option value="SIMPLIFIED_TAX_INVOICE">Simplified tax invoice</option></select></div>
      <div className="form-group"><label htmlFor="invoice-number">Invoice number</label><input id="invoice-number" className="field" required value={invoiceNumber} onChange={(event) => setInvoiceNumber(event.target.value)} /></div>
      <div className="form-group"><label htmlFor="issue-date">Issue date</label><input id="issue-date" className="field" type="date" required value={issueDate} onChange={(event) => setIssueDate(event.target.value)} /></div>
      <div className="form-group"><label htmlFor="source-document">Source document ID</label><input id="source-document" className="field mono" required value={sourceDocumentId} onChange={(event) => setSourceDocumentId(event.target.value)} /><span className="field-help">Used with the source system for duplicate control.</span></div>

      <h3 className="section-title">Seller and buyer</h3>
      <div className="form-group"><label htmlFor="supplier">Registered supplier</label><select id="supplier" className="select" required value={supplierVat} onChange={(event) => setSupplierVat(event.target.value)}>{taxpayers.map((item) => <option key={item.id} value={item.vatNumber}>{item.legalName} · {item.vatNumber}</option>)}</select></div>
      <div className="form-group"><label htmlFor="customer-vat">Buyer VAT registration</label><select id="customer-vat" className="select" value={customerVat} onChange={(event) => chooseCustomer(event.target.value)}><option value="">Not VAT registered / consumer</option>{taxpayers.filter((item) => item.vatNumber !== supplierVat).map((item) => <option key={item.id} value={item.vatNumber}>{item.legalName} · {item.vatNumber}</option>)}</select></div>
      <div className="form-group full"><label htmlFor="customer-name">Buyer name</label><input id="customer-name" className="field" required value={customerName} onChange={(event) => setCustomerName(event.target.value)} /></div>

      <h3 className="section-title">Invoice lines</h3>
      <div className="line-editor">
        <div className="line-row header"><span>Description</span><span>Quantity</span><span>Unit price</span><span>VAT class</span><span>Line total</span><span /></div>
        {lines.map((line) => {
          const net = amount(Number(line.quantity) * Number(line.unitPrice));
          return <div className="line-row" key={line.id}>
            <input className="field" aria-label="Line description" required placeholder="Goods or services" value={line.description} onChange={(event) => updateLine(line.id, "description", event.target.value)} />
            <input className="field" aria-label="Quantity" required type="number" min="0" step="0.000001" value={line.quantity} onChange={(event) => updateLine(line.id, "quantity", event.target.value)} />
            <input className="field" aria-label="Unit price" required type="number" step="0.01" value={line.unitPrice} onChange={(event) => updateLine(line.id, "unitPrice", event.target.value)} />
            <select className="select" aria-label="VAT category" value={line.category} onChange={(event) => updateLine(line.id, "category", event.target.value)}><option value="STANDARD">Standard 15%</option><option value="ZERO_RATED">Zero-rated</option><option value="EXEMPT">Exempt</option></select>
            <span className="amount">{formatMoney(Math.round((net + net * Number(line.rate) / 100) * 100))}</span>
            <button className="icon-button" type="button" aria-label="Remove line" disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((item) => item.id !== line.id))}>×</button>
          </div>;
        })}
        <div style={{ padding: 10 }}><button className="btn btn-secondary" type="button" onClick={() => setLines((current) => [...current, newLine()])}>+ Add line</button></div>
      </div>

      <div className="form-group full"><div className="totals-card">
        <div className="total-line"><span>Tax-exclusive value</span><strong>{formatMoney(Math.round(totals.net * 100))}</strong></div>
        <div className="total-line"><span>VAT</span><strong>{formatMoney(Math.round(totals.tax * 100))}</strong></div>
        <div className="total-line grand"><span>Payable amount</span><strong>{formatMoney(Math.round(totals.total * 100))}</strong></div>
      </div></div>
      <div className="form-group full"><div className="actions" style={{ justifyContent: "flex-end" }}><button className="btn btn-secondary" type="button" onClick={() => router.push("/invoices")}>Cancel</button><button className="btn btn-primary" type="submit" disabled={submitting}>{submitting ? "Validating and certifying…" : "Validate and certify"}</button></div></div>
    </div>
  </form>;
}

