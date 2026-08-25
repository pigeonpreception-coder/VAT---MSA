import { handleBusinessPost } from "@/lib/api/business";

export async function POST(request: Request) {
  return handleBusinessPost(request, "inventory:manage", "TRANSFER_STOCK");
}
