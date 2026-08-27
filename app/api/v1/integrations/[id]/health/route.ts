import { handleIntegrationHealth } from "@/lib/api/integration";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleIntegrationHealth(request, id);
}
