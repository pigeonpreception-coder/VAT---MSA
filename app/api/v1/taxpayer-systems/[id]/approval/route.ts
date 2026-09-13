import { handleTaxpayerSystemCommand } from "@/lib/api/taxpayer-system";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleTaxpayerSystemCommand(request, "APPROVE_TAXPAYER_SYSTEM", id);
}
