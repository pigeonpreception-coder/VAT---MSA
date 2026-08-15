import { handleBusinessPost } from "@/lib/api/business";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleBusinessPost(request, "quotations:manage", "EXPIRE_QUOTATION", id);
}
