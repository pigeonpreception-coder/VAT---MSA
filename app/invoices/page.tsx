import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { listInvoices } from "@/lib/data/repository";
import { InvoiceTable } from "./InvoiceTable";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Tax invoices" };
export const dynamic = "force-dynamic";

export default async function InvoicesPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "invoices:read", { operationClass: "READ" });
  const invoices = await listInvoices(user);
  return <AppShell active="invoices" permission="invoices:read">
    <PageHeader eyebrow="Invoice domain" title="Certified tax invoices" description="Every accepted document is validated, certified and linked to a controlled VAT transaction." actions={<Link className="btn btn-primary" href="/invoices/new">+ Submit invoice</Link>} />
    <InvoiceTable invoices={invoices} />
  </AppShell>;
}
