import { handleLogisticsCommand, handleLogisticsList } from "@/lib/api/logistics";

export async function GET(request: Request) {
  return handleLogisticsList(request);
}

export async function POST(request: Request) {
  return handleLogisticsCommand(request, "CREATE_LOGISTICS_DELIVERY");
}
