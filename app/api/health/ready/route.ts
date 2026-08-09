import { ensureDatabase } from "@/db/runtime";
import { correlationIdFor } from "@/lib/security/request";

export async function GET(request: Request) {
  const correlationId = correlationIdFor(request);
  try {
    const db = await ensureDatabase();
    await db.prepare("SELECT 1 AS ready").first();
    return Response.json({ status: "READY", service: "vat-msa-web", timestamp: new Date().toISOString() }, { headers: { "cache-control": "no-store", "x-correlation-id": correlationId } });
  } catch {
    return Response.json({ status: "NOT_READY", service: "vat-msa-web", correlation_id: correlationId }, { status: 503, headers: { "cache-control": "no-store", "retry-after": "5", "x-correlation-id": correlationId } });
  }
}
