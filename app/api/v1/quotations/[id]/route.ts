import { handleBusinessPost } from "@/lib/api/business";

export async function PATCH(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleBusinessPost(request, "quotations:manage", "UPDATE_QUOTATION", id);
}
