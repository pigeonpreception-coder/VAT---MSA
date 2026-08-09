import { correlationIdFor } from "@/lib/security/request";

export async function GET(request: Request) {
  const correlationId = correlationIdFor(request);
  return Response.json({ status: "UP", service: "vat-msa-web", version: "0.3.0", timestamp: new Date().toISOString() }, {
    headers: { "cache-control": "no-store", "x-correlation-id": correlationId },
  });
}
