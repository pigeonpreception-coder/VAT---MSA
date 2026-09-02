import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request) {
  return handleComplianceCommand(request, "communications:manage", "SEND_NOTICE");
}
