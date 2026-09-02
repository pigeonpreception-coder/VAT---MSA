import { handleInventoryAvailability } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleInventoryAvailability(request);
}
