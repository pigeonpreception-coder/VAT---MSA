import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Ongoing project reports" };
export const dynamic = "force-dynamic";

export default async function OngoingProjectsPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "projects:read", { operationClass: "READ" });
  return <AppShell active="ongoing-projects" permission="projects:read">
    <PlannedModule eyebrow="Project Management" title="Ongoing Project Reports"
      description="Real-time/periodic progress reporting for active projects."
      scopeNote="Not yet built. Depends on the same dedicated project domain model as Create New Project." />
  </AppShell>;
}
