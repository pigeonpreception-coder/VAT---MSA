import { handlePlatformChangeRequestCreate, handlePlatformChangeRequestList } from "@/lib/api/platform";

export async function GET(request: Request) {
  return handlePlatformChangeRequestList(request);
}

export async function POST(request: Request) {
  return handlePlatformChangeRequestCreate(request);
}
