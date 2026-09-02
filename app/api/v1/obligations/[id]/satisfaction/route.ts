import { handleComplianceCommand } from "@/lib/api/compliance";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleComplianceCommand(request, "obligations:manage", "MARK_OBLIGATION_SATISFIED", id);
}
