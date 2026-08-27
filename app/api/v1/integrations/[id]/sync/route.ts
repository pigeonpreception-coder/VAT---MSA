import { handleIntegrationCommand } from "@/lib/api/integration";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleIntegrationCommand(request, "START_SYNC", id);
}
