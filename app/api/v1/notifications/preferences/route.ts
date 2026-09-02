import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request) {
  return handleComplianceCommand(request, "dashboard:read", "UPDATE_NOTIFICATION_PREFERENCE");
}
