import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { getInvoiceById } from "@/lib/data/repository";

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const correlationId = crypto.randomUUID();
  try {
    const user = await getCurrentUser();
    requirePermission(user, "invoices:read");
    const { id } = await params;
    const invoice = await getInvoiceById(id, user);
    if (!invoice) return Response.json({ type: "https://vat-msa.local/problems/not-found", title: "Not found", status: 404, correlation_id: correlationId }, { status: 404 });
    return Response.json(invoice, { headers: { "x-correlation-id": correlationId } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return Response.json({ title: "Forbidden", status: error.status, detail: error.message, correlation_id: correlationId }, { status: error.status });
    return Response.json({ title: "Internal error", status: 500, correlation_id: correlationId }, { status: 500 });
  }
}
