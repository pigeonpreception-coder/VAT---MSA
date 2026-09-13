import { handleTaxpayerSystemGet } from "@/lib/api/taxpayer-system";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleTaxpayerSystemGet(request, id);
}
