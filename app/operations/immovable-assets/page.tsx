import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Immovable asset management" };
export const dynamic = "force-dynamic";

export default async function ImmovableAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "expenses:read", { operationClass: "READ" });
  return <AppShell active="immovable-assets" permission="expenses:read">
    <PlannedModule eyebrow="Operations" title="Immovable Asset Management"
      description="Land and buildings register, valuation and disposal tracking."
      scopeNote="Reserved navigation only, by design. This module is not to be developed until separately instructed." />
  </AppShell>;
}
