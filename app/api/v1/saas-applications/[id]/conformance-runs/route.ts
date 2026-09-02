import { handleSaasCommand } from "@/lib/api/saas";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleSaasCommand(request, "SUBMIT_CONFORMANCE", id);
}
