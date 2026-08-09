import { handleVatCommand } from "@/lib/api/vat-lifecycle";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleVatCommand(request, "returns:submit", "SUBMIT_RETURN", id);
}
