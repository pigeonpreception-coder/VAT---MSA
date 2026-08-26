import { handleAuditTrailSearch } from "@/lib/api/audit";

export async function GET(request: Request) {
  return handleAuditTrailSearch(request);
}
