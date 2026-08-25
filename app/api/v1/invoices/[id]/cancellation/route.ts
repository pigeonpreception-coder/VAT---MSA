import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { cancelInvoice, RepositoryConflictError } from "@/lib/data/repository";
import { InvoiceValidationError } from "@/lib/domain/invoice";
import { readBoundedJson, requestContext, RequestGuardError } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

function problem(status: number, title: string, detail: string, correlationId: string, errors?: unknown) {
  return Response.json({ type: `https://vat-msa.local/problems/${title.toLowerCase().replaceAll(" ", "-")}`, title, status, detail, correlation_id: correlationId, ...(errors ? { errors } : {}) }, {
    status,
    headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId },
  });
}

/**
 * Module 2 Phase B CancelInvoice: { reason }. Officer-only (invoices:cancel,
 * held only by PILOT_ADMIN/NAMRA_SYSTEM_ADMIN, not the submitting taxpayer)
 * and step-up gated, matching the sensitivity of suspending a taxpayer
 * outright. See cancelInvoice in lib/data/repository.ts for eligibility.
 */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "invoices:cancel");
    requireStepUp(request, actor);
    const { id } = await params;
    const cancellation = await cancelInvoice(actor, id, await readBoundedJson(request, 4_096), context.correlationId);
    return Response.json({ cancellation }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof RequestGuardError) return problem(error.status, "Bad request", error.message, context.correlationId);
    if (error instanceof InvoiceValidationError) return problem(422, "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof RepositoryConflictError) return problem(409, "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "Internal error", "The invoice could not be cancelled.", context.correlationId);
  }
}
