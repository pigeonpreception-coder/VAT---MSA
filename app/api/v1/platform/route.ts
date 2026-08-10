import { handlePlatformList } from "@/lib/api/platform";

export async function GET(request: Request) { return handlePlatformList(request); }
