import { handleLogisticsCommand } from "@/lib/api/logistics";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleLogisticsCommand(request, "DELIVER_LOGISTICS_DELIVERY", id);
}
