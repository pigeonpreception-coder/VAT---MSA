import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Cash flow projects" };
export const dynamic = "force-dynamic";

export default async function CashFlowPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="cash-flow" permission="accounting:read">
    <PlannedModule eyebrow="Accounting & Finance" title="Cash Flow Projects"
      description="Project-level cash flow forecasting and monitoring."
      scopeNote="Not yet built. Project Management does not yet have a dedicated project domain model to derive cash flow from." />
  </AppShell>;
}
