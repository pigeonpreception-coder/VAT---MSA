import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { InvoiceValidationError } from "@/lib/domain/invoice";
import type { InvoiceSubmission } from "@/lib/domain/types";
import { listInvoices, RepositoryConflictError, submitInvoice } from "@/lib/data/repository";

function problem(status: number, title: string, detail: string, correlationId: string, errors?: unknown) {
  return Response.json({ type: `https://vat-msa.local/problems/${title.toLowerCase().replaceAll(" ", "-")}`, title, status, detail, correlation_id: correlationId, ...(errors ? { errors } : {}) }, {
    status,
    headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId },
  });
}

export async function GET() {
  const correlationId = crypto.randomUUID();
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:read");
    return Response.json({ invoices: await listInvoices() }, { headers: { "x-correlation-id": correlationId } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
    return problem(500, "Internal error", "The invoice list is temporarily unavailable.", correlationId);
  }
}

export async function POST(request: Request) {
  const correlationId = request.headers.get("x-correlation-id") || crypto.randomUUID();
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:submit");
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await request.json() as InvoiceSubmission;
    const invoice = await submitInvoice(payload, user, idempotencyKey);
    const verificationUrl = new URL(`/verify/${invoice.verificationToken}`, request.url).toString();
    return Response.json({
      invoice_id: invoice.id,
      transaction_id: invoice.transactionId,
      certificate_id: invoice.certificateId,
      status: "CERTIFIED",
      certified_at: invoice.certifiedAt,
      rule_set_version: "NA-VAT-PILOT-2026.1",
      invoice_hash: invoice.payloadHash,
      signature: invoice.signature,
      verification_url: verificationUrl,
      qr_payload: verificationUrl,
    }, { status: 201, headers: { "x-correlation-id": correlationId } });
  } catch (error) {
    if (error instanceof SyntaxError) return problem(400, "Bad request", "The request body is not valid JSON.", correlationId);
    if (error instanceof InvoiceValidationError) return problem(422, "Validation failed", error.message, correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof RepositoryConflictError) return problem(409, "Conflict", error.message, correlationId);
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
    return problem(500, "Internal error", "The invoice could not be certified. Retry with the same idempotency key.", correlationId);
  }
}
