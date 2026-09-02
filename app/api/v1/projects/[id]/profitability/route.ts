import { handleProjectProfitability } from "@/lib/api/business";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleProjectProfitability(request, id);
}
