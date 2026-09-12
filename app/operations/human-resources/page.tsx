import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Human resources module" };
export const dynamic = "force-dynamic";

export default async function HumanResourcesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "expenses:read", { operationClass: "READ" });
  return <AppShell active="human-resources" permission="expenses:read">
    <PlannedModule eyebrow="Operations" title="Human Resources Module"
      description="Employee records, payroll integration boundaries and HR workflows."
      scopeNote="Reserved navigation only, by design. This module is not to be developed until separately instructed." />
  </AppShell>;
}
