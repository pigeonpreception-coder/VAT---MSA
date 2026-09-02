import { handleBusinessPost, handleQuotationSearch } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleQuotationSearch(request);
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "quotations:manage", "CREATE_QUOTATION");
}
