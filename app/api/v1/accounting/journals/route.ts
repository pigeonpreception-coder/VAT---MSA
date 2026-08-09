import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "accounting:read", "journals");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "accounting:post", "POST_JOURNAL");
}
