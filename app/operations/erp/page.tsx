import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "ERP module" };
export const dynamic = "force-dynamic";

export default async function ErpModulePage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "expenses:read", { operationClass: "READ" });
  return <AppShell active="erp" permission="expenses:read">
    <PlannedModule eyebrow="Operations" title="ERP Module"
      description="Broader enterprise resource planning integration boundary."
      scopeNote="Reserved navigation only, by design. This module is not to be developed until separately instructed." />
  </AppShell>;
}
