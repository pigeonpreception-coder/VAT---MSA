import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "New credit note" };
export const dynamic = "force-dynamic";

export default async function NewCreditNotePage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:submit");
  return <AppShell active="new-credit-note" permission="invoices:submit">
    <PlannedModule eyebrow="New Registration" title="New Credit Note"
      description="A controlled form to issue a credit note against an original tax invoice."
      scopeNote={"This form is not yet built, per the change-control rule that an unapproved form must be proposed before it is built. The backend already validates a credit note (document_type CREDIT_NOTE) that references the original document, so the proposed form is: original invoice reference (required), reason for the credit, and the lines to reverse — with amounts recorded as a reduction and posted as a negative payable total. Awaiting approval before the UI is built."} />
  </AppShell>;
}
