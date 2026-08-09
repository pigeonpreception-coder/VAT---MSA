import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { InvoiceValidationError } from "@/lib/domain/invoice";
import type { InvoiceSubmission } from "@/lib/domain/types";
import { listInvoices, RepositoryConflictError, submitInvoice } from "@/lib/data/repository";
import {
  emitStructuredSecurityLog,
  enforceInvoiceRateLimits,
  readBoundedJson,
  recordSecurityEvent,
  requestContext,
  RequestGuardError,
} from "@/lib/security/request";

function problem(status: number, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${title.toLowerCase().replaceAll(" ", "-")}`, title, status, detail, correlation_id: correlationId, ...(errors ? { errors } : {}) }, {
    status,
    headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) },
  });
}

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:read");
    return Response.json({ invoices: await listInvoices(user) }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "Internal error", "The invoice list is temporarily unavailable.", context.correlationId);
  }
}

export async function POST(request: Request) {
  const startedAt = Date.now();
  const context = await requestContext(request);
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "invoices:submit");
    await enforceInvoiceRateLimits(user, context);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<InvoiceSubmission>(request);
    if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
      throw new RequestGuardError(400, "INVALID_DOCUMENT", "The request body must contain an invoice object.");
    }
    const invoice = await submitInvoice(payload, user, idempotencyKey, context);
    const verificationUrl = new URL(`/verify/${invoice.verificationToken}`, request.url).toString();
    emitStructuredSecurityLog({ level: "INFO", event: "INVOICE_SUBMISSION", correlationId: context.correlationId, actorId, outcome: "CERTIFIED", durationMs: Date.now() - startedAt });
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
    }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof RequestGuardError || error instanceof AccessDeniedError ? "WARN" : "ERROR", event: "INVOICE_SUBMISSION", correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: "INVOICE_SUBMISSION", outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof InvoiceValidationError) return problem(422, "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof RepositoryConflictError) return problem(409, "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "INVOICE_SUBMISSION", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "Internal error", "The invoice could not be certified. Retry with the same idempotency key.", context.correlationId);
  }
}
