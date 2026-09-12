import { handleFixedAssetCommand, handleFixedAssetList } from "@/lib/api/fixed-asset";

export async function GET(request: Request) {
  return handleFixedAssetList(request);
}

export async function POST(request: Request) {
  return handleFixedAssetCommand(request, "REGISTER_FIXED_ASSET");
}
