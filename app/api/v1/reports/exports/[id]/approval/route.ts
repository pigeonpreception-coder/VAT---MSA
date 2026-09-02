import { handleReportExportApproval } from "@/lib/api/platform";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleReportExportApproval(request, id);
}
