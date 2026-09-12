import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Converted quotations" };
export const dynamic = "force-dynamic";

export default async function ConvertedQuotationsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "commercial:read", { operationClass: "READ" });
  return <AppShell active="converted-quotations" permission="commercial:read">
    <PlannedModule eyebrow="Quotation" title="Converted Quotations"
      description="Quotations that have progressed to a purchase order or invoice."
      scopeNote="Not yet built as a dedicated view. Quotation status and conversion actions already exist on the quotation register." />
  </AppShell>;
}
