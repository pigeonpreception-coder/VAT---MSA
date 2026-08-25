import { handleExpenseReport } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleExpenseReport(request);
}
