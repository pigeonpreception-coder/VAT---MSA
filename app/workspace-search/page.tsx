import type { Metadata } from "next";
import Link from "next/link";
import { AppShell } from "@/components/AppShell";
import { PageHeader } from "@/components/PageHeader";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { searchWorkspace } from "@/lib/data/control-plane-repository";

export const metadata: Metadata = { title: "Workspace search" };
export const dynamic = "force-dynamic";

export default async function WorkspaceSearchPage({ searchParams }: { searchParams: Promise<{ q?: string }> }) {
  const actor = await getCurrentUser();
  await requireLicensedPermission(actor, "search:read", { operationClass: "READ" });
  const query = (await searchParams).q ?? "";
  const results = await searchWorkspace(actor, query);
  return <AppShell active="search" permission="search:read">
    <PageHeader eyebrow="Permission-aware discovery" title="Search your authorised workspace" description="Results are tenant-filtered on the server and appear only when both the relevant permission and licence entitlement permit access." />
    <section className="panel search-panel">
      <form className="filters" method="get"><label className="sr-only" htmlFor="workspace-search">Search records</label><input className="field" id="workspace-search" name="q" defaultValue={query} minLength={2} maxLength={80} placeholder="Employee, invoice or role" /><button className="btn btn-primary">Search</button></form>
      {query.length < 2 ? <div className="empty"><strong>Enter at least two characters</strong>Search operates only inside the active licensed organisation.</div> : results.length ? <div className="search-results">{results.map((result) => <Link className="search-result" href={result.href} key={`${result.type}-${result.id}`}><span className="status">{result.type}</span><div><strong>{result.title}</strong><p>{result.subtitle}</p></div><span aria-hidden="true">→</span></Link>)}</div> : <div className="empty"><strong>No authorised matches</strong>Records outside your tenant, role or licence scope are never returned.</div>}
    </section>
  </AppShell>;
}
