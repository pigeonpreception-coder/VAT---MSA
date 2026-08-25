import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "accounting:read", "accounts");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "accounting:post", "CREATE_ACCOUNT");
}
