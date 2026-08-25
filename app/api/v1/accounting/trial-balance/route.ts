import { handleTrialBalance } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleTrialBalance(request);
}
