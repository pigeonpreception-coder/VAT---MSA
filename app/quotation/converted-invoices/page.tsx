import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Converted quotations into invoices" };
export const dynamic = "force-dynamic";

export default async function ConvertedQuotationsIntoInvoicesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "commercial:read", { operationClass: "READ" });
  return <AppShell active="converted-into-invoices" permission="commercial:read">
    <PlannedModule eyebrow="Quotation" title="Converted Quotations into Invoices"
      description="The invoice, credit notes, debit notes and related quotation for each converted quotation, in one list."
      scopeNote="Not yet built as a dedicated cross-reference view. The invoice and quotation records this would join already exist independently." />
  </AppShell>;
}
