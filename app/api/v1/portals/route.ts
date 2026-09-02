import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { getAvailablePortals } from "@/lib/portals";
import { requestContext } from "@/lib/security/request";

/**
 * Module 1 Buyer/Seller GetAvailablePortals, exposed as its own endpoint.
 * getAvailablePortals() already existed and gates every /portal/* page
 * server-side (components/PortalShell.tsx) — this route just makes the same
 * self-scoped answer independently callable, e.g. for a client-side portal
 * switcher or an external API consumer, without rendering a full page.
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    return identityJson({ portals: await getAvailablePortals(actor) }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
