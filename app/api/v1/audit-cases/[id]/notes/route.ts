import { handleCaseNotes, handleComplianceCommand } from "@/lib/api/compliance";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleCaseNotes(request, id);
}

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleComplianceCommand(request, "cases:manage", "ADD_CASE_NOTE", id);
}
