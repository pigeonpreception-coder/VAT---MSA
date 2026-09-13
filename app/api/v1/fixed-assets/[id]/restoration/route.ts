import { handleFixedAssetCommand } from "@/lib/api/fixed-asset";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  const { id } = await context.params;
  return handleFixedAssetCommand(request, "RESTORE_FIXED_ASSET", id);
}
