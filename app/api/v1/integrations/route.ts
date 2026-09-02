import { handleIntegrationCommand } from "@/lib/api/integration";

export async function POST(request: Request) {
  return handleIntegrationCommand(request, "REGISTER_INTEGRATION");
}
