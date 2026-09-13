import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Local invoices" };
export const dynamic = "force-dynamic";

export default async function LocalInvoicesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:read", { operationClass: "READ" });
  return <AppShell active="local-invoices" permission="invoices:read">
    <PlannedModule eyebrow="Invoice Management" title="Local Invoices"
      description="Issued invoices, received invoices, credit notes and debit notes classified as domestic (Namibia)."
      scopeNote="Automatic local/foreign classification requires recording the counterparty's registered country on business-party records, which is not yet captured. Until that data and the classification rule ship, see All Invoices for the unified register." />
  </AppShell>;
}
