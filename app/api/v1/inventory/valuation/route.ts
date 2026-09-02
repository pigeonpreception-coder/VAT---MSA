import { handleInventoryValuation } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleInventoryValuation(request);
}
