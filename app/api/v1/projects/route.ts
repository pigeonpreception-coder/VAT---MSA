import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "projects:read", "projects");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "projects:manage", "CREATE_PROJECT");
}
