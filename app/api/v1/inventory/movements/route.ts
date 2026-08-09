import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "inventory:read", "balances");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "inventory:manage", "RECORD_STOCK_MOVEMENT");
}
