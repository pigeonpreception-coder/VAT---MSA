import { handleSaasCommand } from "@/lib/api/saas";

export async function POST(request: Request) {
  return handleSaasCommand(request, "REGISTER_PROVIDER");
}
