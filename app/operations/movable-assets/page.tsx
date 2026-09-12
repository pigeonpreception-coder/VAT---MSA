import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Movable asset management" };
export const dynamic = "force-dynamic";

export default async function MovableAssetsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "expenses:read", { operationClass: "READ" });
  return <AppShell active="movable-assets" permission="expenses:read">
    <PlannedModule eyebrow="Operations" title="Movable Asset Management"
      description="Vehicles, equipment and other movable assets register and tracking."
      scopeNote="Reserved navigation only, by design. This module is not to be developed until separately instructed." />
  </AppShell>;
}
