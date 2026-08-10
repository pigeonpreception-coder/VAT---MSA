import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request) {
  return handleComplianceCommand(request, "refunds:request", "REQUEST_REFUND");
}
