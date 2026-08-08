import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getPublicVerification } from "@/lib/data/repository";
import { formatDateTime, formatMoney, maskInvoiceNumber } from "@/lib/format";

export const metadata: Metadata = { title: "Verify VAT certificate" };
export const dynamic = "force-dynamic";

export default async function VerifyPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const record = await getPublicVerification(token);
  if (!record) notFound();
  const valid = record.certificate_status === "VALID";
  return <main className="verification-shell">
    <article className="verification-card">
      <div className="verification-top">
        <div className="verification-seal" aria-hidden="true">{valid ? "OK" : "!"}</div>
        <p style={{ margin: "18px 0 5px", opacity: .8, fontSize: 11, textTransform: "uppercase", letterSpacing: ".12em", fontWeight: 800 }}>VAT-MSA certificate verification</p>
        <h1 style={{ color: "white" }}>{valid ? "Valid pilot certificate" : "Certificate requires attention"}</h1>
        <p style={{ margin: "9px 0 0", opacity: .78, fontSize: 12 }}>Privacy-minimised verification. Full taxpayer invoice data is not disclosed.</p>
      </div>
      <div className="verification-body">
        <div className="detail-grid">
          <div className="detail" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Supplier</span><strong>{record.supplier_name}</strong></div>
          <div className="detail"><span className="detail-label">Invoice</span><strong>{maskInvoiceNumber(record.invoice_number)}</strong></div>
          <div className="detail"><span className="detail-label">Gross amount</span><strong>{formatMoney(record.total_cents, record.currency)}</strong></div>
          <div className="detail"><span className="detail-label">Status</span><strong>{record.certificate_status}</strong></div>
          <div className="detail" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Certified at</span><strong>{formatDateTime(record.issued_at)}</strong></div>
          <div className="detail" style={{ gridColumn: "1 / -1" }}><span className="detail-label">Invoice fingerprint</span><strong className="mono">{record.invoice_hash}</strong></div>
        </div>
        <div className="alert alert-error" style={{ marginTop: 18 }}>Pilot certificate only. Production legal signatures require the approved NamRA signing profile and protected HSM keys.</div>
        <div className="actions" style={{ marginTop: 18 }}><Link className="btn btn-secondary" href="/">Return to VAT-MSA</Link></div>
      </div>
    </article>
  </main>;
}
