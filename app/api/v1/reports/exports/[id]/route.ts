import { handleReportExportStatus } from "@/lib/api/platform";

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleReportExportStatus(request, id);
}
