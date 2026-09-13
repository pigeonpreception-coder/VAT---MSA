import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "VAT adjustment report" };
export const dynamic = "force-dynamic";

export default async function VatAdjustmentReportPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "compliance:read", { operationClass: "READ" });
  return <AppShell active="vat-adjustment-report" permission="compliance:read">
    <PlannedModule eyebrow="VAT Management" title="VAT Adjustment Report"
      description="A summary of credit and debit note adjustments against filed VAT periods."
      scopeNote="This report format is not yet approved. The underlying credit/debit note and VAT-adjustment data already exists in the platform's invoice and VAT-period records." />
  </AppShell>;
}
