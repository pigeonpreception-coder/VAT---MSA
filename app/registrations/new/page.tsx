import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { RegistrationForm } from "./RegistrationForm";

export const metadata: Metadata = { title: "New taxpayer registration" };
export const dynamic = "force-dynamic";

export default async function NewRegistrationPage() {
  const user = await getCurrentUser();
  requirePermission(user, "registrations:submit");
  return <AppShell active="registrations" permission="registrations:submit">
    <PageHeader eyebrow="Registration application" title="Capture the legal taxpayer identity" description="Enter authoritative identifiers once. VAT-MSA checks duplicates and records an auditable request for ITAS/NamRA verification before creating an organisation." />
    <RegistrationForm />
  </AppShell>;
}
