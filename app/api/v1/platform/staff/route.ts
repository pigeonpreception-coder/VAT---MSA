import { handleProvisionPlatformStaff } from "@/lib/api/platform";

export async function POST(request: Request) {
  return handleProvisionPlatformStaff(request);
}
