import { handleReportRun } from "@/lib/api/platform";

export async function POST(request: Request, context: { params: Promise<{ code: string }> }) { const { code } = await context.params; return handleReportRun(request, code); }
