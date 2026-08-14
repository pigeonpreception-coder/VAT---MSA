import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "parties:manage", "parties");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "parties:manage", "CREATE_BUSINESS_PARTY");
}
