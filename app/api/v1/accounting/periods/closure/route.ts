import { handleBusinessPost } from "@/lib/api/business";

export async function POST(request: Request) {
  return handleBusinessPost(request, "accounting:close-period", "CLOSE_ACCOUNTING_PERIOD");
}
