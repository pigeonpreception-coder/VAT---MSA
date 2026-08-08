import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { listTaxpayers } from "@/lib/data/repository";
import { InvoiceForm } from "./InvoiceForm";

export const metadata: Metadata = { title: "Submit invoice" };
export const dynamic = "force-dynamic";

export default async function NewInvoicePage() {
  const taxpayers = (await listTaxpayers()).map((row) => ({ id: String(row.id), legalName: String(row.legal_name), vatNumber: String(row.vat_number) }));
  return <AppShell active="new-invoice" permission="invoices:submit">
    <PageHeader eyebrow="Electronic invoicing" title="Submit a fiscal document" description="The platform validates totals and registrations, prevents duplicates, creates VAT postings, issues a pilot certificate and records tamper-evident audit evidence." />
    <InvoiceForm taxpayers={taxpayers} />
  </AppShell>;
}

