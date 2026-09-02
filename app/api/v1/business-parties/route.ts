import { handleBusinessPost, handlePartySearch } from "@/lib/api/business";

export async function GET(request: Request) {
  return handlePartySearch(request);
}

export async function POST(request: Request) {
  return handleBusinessPost(request, "parties:manage", "CREATE_BUSINESS_PARTY");
}
