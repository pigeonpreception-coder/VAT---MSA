import { handleInbox } from "@/lib/api/compliance";

export async function GET(request: Request) {
  return handleInbox(request);
}
