import { getPublicVerification } from "@/lib/data/repository";
import { maskInvoiceNumber } from "@/lib/format";

export async function GET(_request: Request, { params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const record = await getPublicVerification(token);
  if (!record) return Response.json({ type: "https://vat-msa.local/problems/not-found", title: "Certificate not found", status: 404 }, { status: 404 });
  return Response.json({
    valid: record.certificate_status === "VALID",
    certificate_status: record.certificate_status,
    status: record.status,
    certified_at: record.issued_at,
    supplier_display: record.supplier_name,
    invoice_number_masked: maskInvoiceNumber(record.invoice_number),
    total_amount: (record.total_cents / 100).toFixed(2),
    currency: record.currency,
    is_correction: record.is_correction,
    correction_type: record.correction_type,
    corrects_invoice_number_masked: record.corrects_invoice_number ? maskInvoiceNumber(record.corrects_invoice_number) : null,
    corrections: record.corrections.map((correction) => ({
      correction_type: correction.correction_type,
      status: correction.status,
      invoice_number_masked: maskInvoiceNumber(correction.invoice_number),
      total_amount: (correction.total_cents / 100).toFixed(2),
      created_at: correction.created_at,
    })),
  });
}

