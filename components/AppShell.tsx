import { getCurrentUser } from "@/lib/auth";
import { getEffectiveNavigation } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { initials } from "@/lib/format";
import { WorkspaceNavigation } from "@/components/WorkspaceNavigation";

export async function AppShell({ active, permission, children }: { active: string; permission: string; children: React.ReactNode }) {
  const user = await getCurrentUser();
  await requireLicensedPermission(user, permission, { operationClass: "READ" });
  const navigation = await getEffectiveNavigation(user);
  const activeItem = navigation.workspaces.flatMap((workspace) => workspace.folders.flatMap((folder) => folder.items.map((item) => ({ workspace, folder, item })))).find(({ item }) => item.key === active);

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand">
          <div className="brand-mark" aria-hidden="true">V</div>
          <div><strong>VAT-MSA</strong><small>Fiscal transaction platform</small></div>
        </div>
        <WorkspaceNavigation workspaces={navigation.workspaces} active={active} />
        <div className="sidebar-foot">
          {navigation.organisation.legal_name}<br />Local staging · synthetic data
        </div>
      </aside>
      <main className="main">
        <header className="topbar">
          <div><div className="env-pill"><span className="pulse" /> Controlled pilot</div>{activeItem ? <div className="breadcrumb">{activeItem.workspace.label} / {activeItem.item.label}</div> : null}</div>
          <div className="user-block">
            <div><strong>{user.displayName}</strong><span>{user.role.replaceAll("_", " ")}{user.isDevelopmentIdentity ? " - local identity" : ""}</span></div>
            <div className="avatar" aria-hidden="true">{initials(user.displayName)}</div>
          </div>
        </header>
        <div className="content">
          {["SUSPENDED", "EXPIRED", "CANCELLED"].includes(navigation.license.state) ? <div className="notice notice-warning" role="status">
            <strong>Licence continuity mode</strong>
            Historical reads, authorised exports, statutory compliance and controlled corrections remain available. New business and privileged administration actions are disabled; records are preserved.
          </div> : null}
          {children}
        </div>
      </main>
    </div>
  );
}
