import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "New debit note" };
export const dynamic = "force-dynamic";

export default async function NewDebitNotePage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:submit");
  return <AppShell active="new-debit-note" permission="invoices:submit">
    <PlannedModule eyebrow="New Registration" title="New Debit Note"
      description="A controlled form to issue a debit note against an original tax invoice."
      scopeNote={"This form is not yet built, per the change-control rule that an unapproved form must be proposed before it is built. The backend already validates a debit note (document_type DEBIT_NOTE) that references the original document, so the proposed form is: original invoice reference (required), reason for the debit, and the additional lines/amounts — posted as a positive payable total. Awaiting approval before the UI is built."} />
  </AppShell>;
}
