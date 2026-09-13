import { handleTaxpayerSystemCommand, handleTaxpayerSystemList } from "@/lib/api/taxpayer-system";

export async function GET(request: Request) {
  return handleTaxpayerSystemList(request);
}

export async function POST(request: Request) {
  return handleTaxpayerSystemCommand(request, "REGISTER_TAXPAYER_SYSTEM");
}
