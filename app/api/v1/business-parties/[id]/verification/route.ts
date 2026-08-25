import { handleBusinessPost, handleSupplierVerificationHistory } from "@/lib/api/business";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleSupplierVerificationHistory(request, id);
}

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleBusinessPost(request, "parties:manage", "VERIFY_SUPPLIER", id);
}
