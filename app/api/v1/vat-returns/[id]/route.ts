import { handleVatReturnDetail } from "@/lib/api/vat-lifecycle";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleVatReturnDetail(request, id);
}
