import { handleComplianceCommand, handleNotifications } from "@/lib/api/compliance";

export async function GET(request: Request) {
  return handleNotifications(request);
}

export async function POST(request: Request) {
  return handleComplianceCommand(request, "notifications:manage", "QUEUE_NOTIFICATION");
}
