import { handleOfflineBatch } from "@/lib/api/platform";

export async function POST(request: Request) { return handleOfflineBatch(request); }
