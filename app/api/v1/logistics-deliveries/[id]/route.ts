import { handleLogisticsGet } from "@/lib/api/logistics";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleLogisticsGet(request, id);
}
