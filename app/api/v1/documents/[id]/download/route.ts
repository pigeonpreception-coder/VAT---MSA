import { handleDocumentDownload } from "@/lib/api/platform";

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleDocumentDownload(request, id);
}
