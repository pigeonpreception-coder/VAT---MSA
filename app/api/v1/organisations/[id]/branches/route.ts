import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { createBranch, listBranches } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

export async function GET(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "identity:read", { operationClass: "READ" });
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
    await requireLicensedPermission(actor, "organisations:manage", { operationClass: "ADMIN_WRITE" });
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const branch = await createBranch(actor, id, payload, context.correlationId);
    return identityJson({ branch }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
