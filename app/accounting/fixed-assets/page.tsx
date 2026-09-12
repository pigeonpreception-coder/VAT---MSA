import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Fixed asset module" };
export const dynamic = "force-dynamic";

export default async function FixedAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="fixed-assets" permission="accounting:read">
    <PlannedModule eyebrow="Accounting & Finance" title="Fixed Asset Module"
      description="Asset register, depreciation schedules and disposal tracking."
      scopeNote="Not yet built. No fixed-asset domain model exists in the platform today." />
  </AppShell>;
}
