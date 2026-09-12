import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Supplier ledger" };
export const dynamic = "force-dynamic";

export default async function SupplierLedgerPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "accounting:read", { operationClass: "READ" });
  return <AppShell active="supplier-ledger" permission="accounting:read">
    <PlannedModule eyebrow="Accounting & Finance" title="Supplier Ledger"
      description="Per-supplier posted balances derived from the general ledger."
      scopeNote="Not yet built as a dedicated sub-ledger view. General Ledger already holds the posted journal entries this would summarise." />
  </AppShell>;
}
