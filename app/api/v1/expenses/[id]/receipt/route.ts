import { handleBusinessPost } from "@/lib/api/business";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleBusinessPost(request, "expenses:manage", "LINK_EXPENSE_RECEIPT", id);
}
