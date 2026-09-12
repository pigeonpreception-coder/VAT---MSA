import type { Metadata } from "next";
import { AppShell } from "@/components/AppShell";
import { PlannedModule } from "@/components/PlannedModule";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";

export const metadata: Metadata = { title: "Service providers" };
export const dynamic = "force-dynamic";

export default async function ServiceProvidersPage() {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, "parties:manage", { operationClass: "READ" });
  return <AppShell active="service-providers" permission="parties:manage">
    <PlannedModule eyebrow="Registered" title="Service Providers"
      description="A categorised register of service providers, distinct from customers and suppliers."
      scopeNote="Not yet built. Business-party records do not yet carry a service-provider relationship or category; Customers and Suppliers are available today under Registered." />
  </AppShell>;
}
