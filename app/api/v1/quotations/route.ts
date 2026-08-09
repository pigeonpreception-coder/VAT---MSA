import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "commercial:read", "quotations");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "quotations:manage", "CREATE_QUOTATION");
}
