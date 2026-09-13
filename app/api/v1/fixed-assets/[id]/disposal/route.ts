import { handleFixedAssetCommand } from "@/lib/api/fixed-asset";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleFixedAssetCommand(request, "DISPOSE_FIXED_ASSET", id);
}
