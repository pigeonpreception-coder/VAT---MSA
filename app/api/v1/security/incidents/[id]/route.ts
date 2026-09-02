import { handleIncidentDetail } from "@/lib/api/security";

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleIncidentDetail(request, id);
}
