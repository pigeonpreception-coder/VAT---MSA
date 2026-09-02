import { handleConversation } from "@/lib/api/compliance";

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleConversation(request, id);
}
