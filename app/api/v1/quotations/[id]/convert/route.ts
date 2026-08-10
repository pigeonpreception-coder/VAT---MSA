import { handleBusinessPost } from "@/lib/api/business";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleBusinessPost(request, "quotations:manage", "CONVERT_QUOTATION", id);
}
