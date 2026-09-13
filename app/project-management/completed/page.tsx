import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Completed projects" };
export const dynamic = "force-dynamic";

export default async function CompletedProjectsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "projects:read", { operationClass: "READ" });
  return <AppShell active="completed-projects" permission="projects:read">
    <PlannedModule eyebrow="Project Management" title="Completed Projects"
      description="Summary lists and reports for finished projects."
      scopeNote="Not yet built. Depends on the same dedicated project domain model as Create New Project." />
  </AppShell>;
}
