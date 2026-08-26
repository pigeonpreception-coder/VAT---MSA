import { handleAnalyticsDataProducts } from "@/lib/api/platform";

export async function GET(request: Request) {
  return handleAnalyticsDataProducts(request);
}
