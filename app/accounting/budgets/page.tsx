import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Budgets" };
export const dynamic = "force-dynamic";

export default async function BudgetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="budgets" permission="accounting:read">
    <PlannedModule eyebrow="Accounting & Finance" title="Budgets"
      description="Budget planning and budget-versus-actual tracking."
      scopeNote="Not yet built. No budget domain model exists in the platform today." />
  </AppShell>;
}
