import { handlePlatformConfig } from "@/lib/api/platform";

export async function GET(request: Request) {
  return handlePlatformConfig(request);
}
