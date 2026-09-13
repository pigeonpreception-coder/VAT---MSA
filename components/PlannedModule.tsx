import { PageHeader } from "@/components/PageHeader";

export function PlannedModule({ eyebrow, title, description, scopeNote }: { eyebrow: string; title: string; description: string; scopeNote: string }) {
  return <>
    <PageHeader eyebrow={eyebrow} title={title} description={description} />
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Reserved for a future release</h2><div className="panel-meta">Architecture and navigation placeholder</div></div><span className="status status-processing">Planned</span></div>
      <div className="panel-body">
        <div className="alert alert-info"><strong>This module is not built yet.</strong><br />{scopeNote}</div>
      </div>
    </section>
  </>;
}
