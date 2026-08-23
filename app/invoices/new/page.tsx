import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { listTaxpayerOptions } from "@/lib/data/repository";
import { InvoiceForm } from "./InvoiceForm";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Submit invoice" };
export const dynamic = "force-dynamic";

export default async function NewInvoicePage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:submit");
  const taxpayers = (await listTaxpayerOptions(user)).map((row) => ({ id: row.id, legalName: row.legal_name, vatNumber: row.vat_number }));
  return <AppShell active="new-invoice" permission="invoices:submit">
    <PageHeader eyebrow="Electronic invoicing" title="Submit a fiscal document" description="The platform validates totals and registrations, prevents duplicates, creates VAT postings, issues a pilot certificate and records tamper-evident audit evidence." />
    <InvoiceForm taxpayers={taxpayers} />
  </AppShell>;
}
