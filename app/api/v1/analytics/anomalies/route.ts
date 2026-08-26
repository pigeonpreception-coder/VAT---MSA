import { handleAnalyticsAnomalies } from "@/lib/api/platform";

export async function GET(request: Request) {
  return handleAnalyticsAnomalies(request);
}
