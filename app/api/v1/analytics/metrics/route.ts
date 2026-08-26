import { handleAnalyticsMetrics } from "@/lib/api/platform";

export async function GET(request: Request) {
  return handleAnalyticsMetrics(request);
}
