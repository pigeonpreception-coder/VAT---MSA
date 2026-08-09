import { handleVatLifecycleList } from "@/lib/api/vat-lifecycle";

export async function GET(request: Request) {
  return handleVatLifecycleList(request);
}
