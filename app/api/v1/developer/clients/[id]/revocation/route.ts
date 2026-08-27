import { handleDeveloperCommand } from "@/lib/api/developer";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleDeveloperCommand(request, "REVOKE_CREDENTIAL", id);
}
