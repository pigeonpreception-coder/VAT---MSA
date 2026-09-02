import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { createBranch, listBranches } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

export async function GET(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const { id } = await contextValue.params;
    return identityJson({ branches: await listBranches(actor, id) }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "organisations:manage");
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const branch = await createBranch(actor, id, payload, context.correlationId);
    return identityJson({ branch }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
