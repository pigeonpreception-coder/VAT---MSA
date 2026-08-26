import { handleIncidentCreate, handleSOCQueue } from "@/lib/api/security";

export async function GET(request: Request) {
  return handleSOCQueue(request);
}

export async function POST(request: Request) {
  return handleIncidentCreate(request);
}
