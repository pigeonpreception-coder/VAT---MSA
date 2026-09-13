import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Create new project" };
export const dynamic = "force-dynamic";

export default async function CreateProjectPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "projects:read", { operationClass: "READ" });
  return <AppShell active="create-project" permission="projects:read">
    <PlannedModule eyebrow="Project Management" title="Create New Project"
      description="Capture project name, customer, description, location, dates, budget, expected revenue, category, VAT treatment and owner."
      scopeNote="Not yet built. Today, project cost tracking is a free-text field on expense records under Operations rather than a first-class project entity — creating this form requires a dedicated project domain model first." />
  </AppShell>;
}
