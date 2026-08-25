import { handleCaseEvidence, handleComplianceCommand } from "@/lib/api/compliance";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleCaseEvidence(request, id);
}

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleComplianceCommand(request, "cases:manage", "ADD_EVIDENCE", id);
}
