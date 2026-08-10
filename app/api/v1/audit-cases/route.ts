import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request) {
  return handleComplianceCommand(request, "cases:manage", "OPEN_AUDIT_CASE");
}
