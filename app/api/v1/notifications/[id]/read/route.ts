import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleComplianceCommand(request, "dashboard:read", "MARK_NOTIFICATION_READ", id);
}
