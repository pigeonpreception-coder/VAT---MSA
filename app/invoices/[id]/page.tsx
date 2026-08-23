import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { AppShell } from "@/components/AppShell";
import { PageHeader, StatusBadge } from "@/components/PageHeader";
import { getInvoiceById } from "@/lib/data/repository";
import { formatDate, formatDateTime, formatMoney } from "@/lib/format";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Invoice evidence" };
export const dynamic = "force-dynamic";

export default async function InvoiceDetailPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<{ created?: string }> }) {
  const [{ id }, query] = await Promise.all([params, searchParams]);
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:read", { operationClass: "READ" });
  const invoice = await getInvoiceById(id, user);
  if (!invoice) notFound();

  return <AppShell active="invoices" permission="invoices:read">
    <PageHeader eyebrow="Certified fiscal evidence" title={invoice.invoiceNumber} description={`${invoice.supplierName} to ${invoice.customerName}`} actions={<><Link className="btn btn-secondary" href="/invoices">Back to invoices</Link><Link className="btn btn-primary" href={`/verify/${invoice.verificationToken}`}>Verify certificate</Link></>} />
    {query.created ? <div className="alert alert-success" style={{ marginBottom: 18 }}><strong>Invoice certified successfully.</strong> The fiscal document, certificate, VAT transaction, ledger entries and audit evidence were committed as one controlled operation.</div> : null}
    <div className="grid-equal">
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Document record</h2><div className="panel-meta">Canonical invoice and processing outcome</div></div><StatusBadge value={invoice.status} /></div><div className="panel-body detail-grid">
        <div className="detail"><span className="detail-label">Issue date</span><strong>{formatDate(invoice.issueDate)}</strong></div>
        <div className="detail"><span className="detail-label">Document type</span><strong>{invoice.documentType.replaceAll("_", " ")}</strong></div>
        <div className="detail"><span className="detail-label">Net value</span><strong>{formatMoney(invoice.lineNetCents, invoice.currency)}</strong></div>
        <div className="detail"><span className="detail-label">VAT amount</span><strong>{formatMoney(invoice.taxCents, invoice.currency)}</strong></div>
        <div className="detail"><span className="detail-label">Supplier VAT</span><strong>{invoice.supplierVatNumber}</strong></div>
        <div className="detail"><span className="detail-label">Customer VAT</span><strong>{invoice.customerVatNumber ?? "Not registered"}</strong></div>
        <div className="detail"><span className="detail-label">Risk classification</span><StatusBadge value={invoice.riskLevel} /></div>
        <div className="detail"><span className="detail-label">Source system</span><strong>{invoice.sourceSystem}</strong></div>
        <div className="detail"><span className="detail-label">Source document</span><strong className="mono">{invoice.sourceDocumentId}</strong></div>
        <div className="detail"><span className="detail-label">Certified at</span><strong>{formatDateTime(invoice.certifiedAt)}</strong></div>
      </div></section>
      <section className="panel"><div className="panel-head"><div><h2 className="panel-title">Certification receipt</h2><div className="panel-meta">Pilot signing profile; production requires approved HSM keys</div></div><StatusBadge value="CERTIFIED" /></div><div className="panel-body detail-grid">
        <div className="detail full" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Certificate ID</span><strong className="mono">{invoice.certificateId}</strong></div>
        <div className="detail full" style={{ gridColumn: "1 / -1" }}><span className="detail-label">VAT transaction ID</span><strong className="mono">{invoice.transactionId}</strong></div>
        <div className="detail full" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Invoice SHA-256</span><strong className="mono">{invoice.payloadHash}</strong></div>
        <div className="detail"><span className="detail-label">Signature profile</span><strong>{invoice.signatureProfile}</strong></div>
        <div className="detail" style={{ gridColumn: "span 2" }}><span className="detail-label">Verification token</span><strong className="mono">{invoice.verificationToken}</strong></div>
      </div></section>
    </div>

    {invoice.correction || invoice.corrections.length ? <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Correction lineage</h2><div className="panel-meta">Original and correction records remain independently certified and reproducible</div></div><StatusBadge value="LINKED" /></div><div className="panel-body">
      {invoice.correction ? <div className="alert alert-info"><strong>This {invoice.correction.correctionType.replaceAll("_", " ").toLowerCase()} corrects <Link className="mono" href={`/invoices/${invoice.correction.originalInvoiceId}`}>{invoice.correction.originalInvoiceNumber}</Link>.</strong><br />{invoice.correction.reasonCode ?? "CORRECTION"}: {invoice.correction.reason}</div> : null}
      {invoice.corrections.length ? <div className="table-wrap" style={{ marginTop: invoice.correction ? 16 : 0 }}><table><thead><tr><th>Correction</th><th>Type</th><th>Reason</th><th>Value</th><th>Status</th><th>Created</th></tr></thead><tbody>{invoice.corrections.map((correction) => <tr key={correction.correctionInvoiceId}><td><Link href={`/invoices/${correction.correctionInvoiceId}`}><strong>{correction.correctionInvoiceNumber}</strong></Link></td><td>{correction.correctionType.replaceAll("_", " ")}</td><td>{correction.reasonCode ?? "CORRECTION"}<div className="muted">{correction.reason}</div></td><td className="amount">{formatMoney(correction.totalCents, invoice.currency)}</td><td><StatusBadge value={correction.status} /></td><td>{formatDateTime(correction.createdAt)}</td></tr>)}</tbody></table></div> : null}
    </div></section> : null}

    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">Invoice lines</h2><div className="panel-meta">Calculation evidence retained with the canonical record</div></div></div><div className="table-wrap"><table><thead><tr><th>#</th><th>Description</th><th>Quantity</th><th>Unit price</th><th>VAT category</th><th>Rate</th><th>Net</th><th>VAT</th></tr></thead><tbody>{invoice.lines.map((line) => <tr key={line.id}><td>{line.lineNumber}</td><td>{line.description}</td><td>{line.quantity} {line.unitCode}</td><td className="amount">{formatMoney(line.unitPriceCents, invoice.currency)}</td><td>{line.taxCategory.replaceAll("_", " ")}</td><td>{(line.taxRateBps / 100).toFixed(2)}%</td><td className="amount">{formatMoney(line.netAmountCents, invoice.currency)}</td><td className="amount">{formatMoney(line.taxAmountCents, invoice.currency)}</td></tr>)}</tbody></table></div></section>

    <section className="panel" style={{ marginTop: 20 }}><div className="panel-head"><div><h2 className="panel-title">VAT sub-ledger postings</h2><div className="panel-meta">Seller output and eligible buyer input are linked by one transaction</div></div></div><div className="table-wrap"><table><thead><tr><th>Taxpayer</th><th>Entry</th><th>Direction</th><th>VAT period</th><th>Amount</th></tr></thead><tbody>{invoice.ledgerEntries.map((entry) => <tr key={entry.id}><td>{entry.taxpayerName}</td><td>{entry.entryType.replaceAll("_", " ")}</td><td>{entry.direction}</td><td>{entry.period}</td><td className="amount">{formatMoney(entry.amountCents, invoice.currency)}</td></tr>)}</tbody></table></div></section>
  </AppShell>;
}
