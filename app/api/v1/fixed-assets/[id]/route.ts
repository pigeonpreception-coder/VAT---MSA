import { handleFixedAssetGet } from "@/lib/api/fixed-asset";

export async function GET(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleFixedAssetGet(request, id);
}
