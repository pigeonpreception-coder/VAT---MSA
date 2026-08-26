import { handleIncidentContainment } from "@/lib/api/security";

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return handleIncidentContainment(request, id);
}
