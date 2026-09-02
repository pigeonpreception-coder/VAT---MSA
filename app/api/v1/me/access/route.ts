import { controlPlaneJson, controlPlaneProblem } from "@/lib/api/control-plane";
import { getCurrentUser, getUserAccess } from "@/lib/auth";
import { requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    return controlPlaneJson(getUserAccess(actor), context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
