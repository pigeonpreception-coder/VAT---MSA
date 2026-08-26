import { handleReportExportCancellation } from "@/lib/api/platform";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleReportExportCancellation(request, id);
}
