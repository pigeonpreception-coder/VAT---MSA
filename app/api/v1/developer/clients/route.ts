import { handleDeveloperCommand } from "@/lib/api/developer";

export async function POST(request: Request) {
  return handleDeveloperCommand(request, "CREATE_CLIENT");
}
