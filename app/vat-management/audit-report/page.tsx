import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "VAT audit report" };
export const dynamic = "force-dynamic";

export default async function VatAuditReportPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "compliance:read", { operationClass: "READ" });
  return <AppShell active="vat-audit-report" permission="compliance:read">
    <PlannedModule eyebrow="VAT Management" title="VAT Audit Report"
      description="A real-time invoice and VAT summary drawn from certified invoices and reconciliation evidence."
      scopeNote="This report format is not yet approved. Today, the closest equivalent data lives in Invoice Reconciliation and Audit Cases & Risk." />
  </AppShell>;
}
