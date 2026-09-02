import { handleBusinessGet, handleBusinessPost } from "@/lib/api/business";

export async function GET(request: Request) {
  return handleBusinessGet(request, "expenses:read", "categories");
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "expenses:manage", "CREATE_EXPENSE_CATEGORY");
}
