import { handleRestrictedRiskQuery } from "@/lib/api/compliance";

export async function GET(request: Request) {
  return handleRestrictedRiskQuery(request);
}
