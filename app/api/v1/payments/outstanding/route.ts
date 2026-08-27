import { handleOutstandingRefunds } from "@/lib/api/payment";

export async function GET(request: Request) {
  return handleOutstandingRefunds(request);
}
