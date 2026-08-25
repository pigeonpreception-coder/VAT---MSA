import { handleFinancialStatements } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleFinancialStatements(request);
}
