import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Purchase orders" };
export const dynamic = "force-dynamic";

export default async function PurchaseOrdersPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="purchase-orders" permission="accounting:read">
    <PlannedModule eyebrow="Accounting & Finance" title="Purchase Orders"
      description="Purchase order issuance, approval and conversion to supplier invoices."
      scopeNote="Not yet built. No purchase-order domain model exists in the platform today." />
  </AppShell>;
}
