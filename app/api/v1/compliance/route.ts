import { handleComplianceList } from "@/lib/api/compliance";

export async function GET(request: Request) {
  return handleComplianceList(request);
}
