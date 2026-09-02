import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getWorkQueue } from "@/lib/data/reconciliation-repository";
import { requestContext } from "@/lib/security/request";

/**
 * Module 3 Phase B GetWorkQueue: ?status=&severity=&assigned_officer_id=&unassigned_only=true&min_age_days=&max_age_days=&limit=&offset=.
 * Tenant-scoped the same way the pre-existing listExceptions is; NamRA/national-scope
 * actors see every taxpayer's exceptions, everyone else only their own.
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "exceptions:read");
    const workQueue = await getWorkQueue(actor, new URL(request.url).searchParams);
    return reconciliationJson({ workQueue }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
