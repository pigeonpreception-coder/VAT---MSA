import { handlePaymentCommand } from "@/lib/api/payment";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handlePaymentCommand(request, "ALLOCATE_PAYMENT", id);
}
