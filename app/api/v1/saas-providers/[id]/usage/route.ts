import { handleSaasUsage } from "@/lib/api/saas";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleSaasUsage(request, id);
}
