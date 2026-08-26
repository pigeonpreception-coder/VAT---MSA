import { handleAuditChainVerificationList, handleAuditChainVerificationTrigger } from "@/lib/api/audit";

export async function GET(request: Request) {
  return handleAuditChainVerificationList(request);
}

export async function POST(request: Request) {
  return handleAuditChainVerificationTrigger(request);
}
