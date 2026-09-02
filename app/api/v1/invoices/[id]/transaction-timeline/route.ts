import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { getTransactionTimeline } from "@/lib/data/repository";

/** Module 2 Phase D GetTransactionTimeline: certification, every correction and any cancellation for one invoice's lineage, as a chronological narrative of VATTransaction events. */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const correlationId = crypto.randomUUID();
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:read");
    const { id } = await params;
    const timeline = await getTransactionTimeline(id, user);
    if (!timeline) return Response.json({ type: "https://vat-msa.local/problems/not-found", title: "Not found", status: 404, correlation_id: correlationId }, { status: 404 });
    return Response.json(timeline, { headers: { "x-correlation-id": correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return Response.json({ title: "Forbidden", status: error.status, detail: error.message, correlation_id: correlationId }, { status: error.status });
    return Response.json({ title: "Internal error", status: 500, correlation_id: correlationId }, { status: 500 });
  }
}
