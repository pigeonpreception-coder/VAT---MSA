import { handleCaseTimeline } from "@/lib/api/compliance";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleCaseTimeline(request, id);
}
