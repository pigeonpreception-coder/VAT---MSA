import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { explainInvoiceVat } from "@/lib/data/repository";

/** Module 2 Phase A ExplainCalculation: per-line trace back to the exact approved VATRule version that produced its tax amount. */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const correlationId = crypto.randomUUID();
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:read");
    const { id } = await params;
    const explanation = await explainInvoiceVat(id, user);
    if (!explanation) return Response.json({ type: "https://vat-msa.local/problems/not-found", title: "Not found", status: 404, correlation_id: correlationId }, { status: 404 });
    return Response.json(explanation, { headers: { "x-correlation-id": correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return Response.json({ title: "Forbidden", status: error.status, detail: error.message, correlation_id: correlationId }, { status: error.status });
    return Response.json({ title: "Internal error", status: 500, correlation_id: correlationId }, { status: 500 });
  }
}
