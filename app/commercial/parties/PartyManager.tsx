"use client";

import { useState, type FormEvent } from "react";
import { StatusBadge } from "@/components/PageHeader";

export type PartyRow = {
  id: string;
  display_name: string;
  legal_name: string | null;
  vat_number: string | null;
  tin: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  relationships: string | null;
  status: string;
};

type ActionState = { kind: "idle" | "working" | "success" | "error"; message: string };

function responseMessages(body: unknown): string[] {
  if (!body || typeof body !== "object") return ["The command failed without a readable response."];
  const value = body as { detail?: string; errors?: Array<{ message?: string }> };
  return value.errors?.map((item) => item.message ?? "Validation error") ?? [value.detail ?? "The command could not be completed."];
}

function relationships(value: string | null) {
  return new Set((value ?? "").split(",").filter(Boolean));
}

export function PartyManager({ organisationId, parties }: { organisationId: string; parties: PartyRow[] }) {
  const [editing, setEditing] = useState<PartyRow | null>(null);
  const [state, setState] = useState<ActionState>({ kind: "idle", message: "" });

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const payload = {
      schema_version: "1.0.0",
      display_name: data.get("display_name"),
      legal_name: data.get("legal_name"),
      vat_number: data.get("vat_number"),
      tin: data.get("tin"),
      email: data.get("email"),
      phone: data.get("phone"),
      address: data.get("address"),
      relationships: data.getAll("relationships"),
    };
    const path = editing ? `/api/v1/business-parties/${encodeURIComponent(editing.id)}` : "/api/v1/business-parties";
    setState({ kind: "working", message: editing ? "Updating trading partner…" : "Creating trading partner…" });
    try {
      const response = await fetch(`${path}?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: editing ? "PATCH" : "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify(payload),
      });
      const body = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(body).join(" "));
      setState({ kind: "success", message: editing ? "Trading partner updated with audit evidence." : "Trading partner created with audit evidence." });
      setEditing(null);
      form.reset();
      window.setTimeout(() => window.location.reload(), 650);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "The trading-partner command failed." });
    }
  }

  async function deactivate(party: PartyRow) {
    const reason = window.prompt(`Why should ${party.display_name} be deactivated? Historical records will be preserved.`)?.trim();
    if (!reason) return;
    setState({ kind: "working", message: `Deactivating ${party.display_name} without deleting historical records…` });
    try {
      const response = await fetch(`/api/v1/business-parties/${encodeURIComponent(party.id)}/deactivation?organisation_id=${encodeURIComponent(organisationId)}`, {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify({ schema_version: "1.0.0", reason }),
      });
      const body = await response.json() as unknown;
      if (!response.ok) throw new Error(responseMessages(body).join(" "));
      setState({ kind: "success", message: `${party.display_name} is inactive. Existing invoices, quotations, expenses and audit evidence remain intact.` });
      window.setTimeout(() => window.location.reload(), 850);
    } catch (error) {
      setState({ kind: "error", message: error instanceof Error ? error.message : "The deactivation failed." });
    }
  }

  const selectedRelationships = relationships(editing?.relationships ?? null);

  return <div className="grid-2">
    <section className="panel">
      <div className="panel-head"><div><h2 className="panel-title">Trading partner register</h2><div className="panel-meta">Active and retained historical records</div></div></div>
      <div className="table-wrap"><table><thead><tr><th>Partner</th><th>Relationship</th><th>Tax identifiers</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead><tbody>
        {parties.map((party) => <tr key={party.id}>
          <td><strong>{party.display_name}</strong><div className="muted">{party.legal_name ?? "No separate legal name"}</div><div className="mono muted">{party.id}</div></td>
          <td>{(party.relationships ?? "Retained history").split(",").map((value) => <StatusBadge key={value} value={value} />)}</td>
          <td>{party.vat_number ? <div>VAT: <span className="mono">{party.vat_number}</span></div> : null}{party.tin ? <div>TIN: <span className="mono">{party.tin}</span></div> : null}{!party.vat_number && !party.tin ? <span className="muted">Not recorded</span> : null}</td>
          <td>{party.email ?? party.phone ?? <span className="muted">Not recorded</span>}</td>
          <td><StatusBadge value={party.status} /></td>
          <td><div className="actions">{party.status === "ACTIVE" ? <><button className="btn btn-secondary" type="button" onClick={() => { setEditing(party); setState({ kind: "idle", message: "" }); }}>Edit</button><button className="btn btn-danger" type="button" onClick={() => deactivate(party)} disabled={state.kind === "working"}>Deactivate</button></> : <span className="muted">Read-only history</span>}</div></td>
        </tr>)}
        {!parties.length ? <tr><td colSpan={6} className="muted">No trading partners have been recorded.</td></tr> : null}
      </tbody></table></div>
    </section>

    <form className="panel" key={editing?.id ?? "new"} onSubmit={submit}>
      <div className="panel-head"><div><h2 className="panel-title">{editing ? "Edit trading partner" : "Add trading partner"}</h2><div className="panel-meta">Tenant-scoped customer and supplier master data</div></div>{editing ? <button className="btn btn-secondary" type="button" onClick={() => { setEditing(null); setState({ kind: "idle", message: "" }); }}>Cancel</button> : null}</div>
      <div className="panel-body form-grid">
        <div className="form-group"><label htmlFor="party-display-name">Display name</label><input className="field" id="party-display-name" name="display_name" required maxLength={200} defaultValue={editing?.display_name ?? ""} /></div>
        <div className="form-group"><label htmlFor="party-legal-name">Legal name</label><input className="field" id="party-legal-name" name="legal_name" maxLength={200} defaultValue={editing?.legal_name ?? ""} /></div>
        <div className="form-group"><label htmlFor="party-vat-number">VAT number</label><input className="field" id="party-vat-number" name="vat_number" maxLength={40} defaultValue={editing?.vat_number ?? ""} /></div>
        <div className="form-group"><label htmlFor="party-tin">TIN</label><input className="field" id="party-tin" name="tin" maxLength={40} defaultValue={editing?.tin ?? ""} /></div>
        <div className="form-group"><label htmlFor="party-email">Email</label><input className="field" id="party-email" name="email" type="email" maxLength={254} defaultValue={editing?.email ?? ""} /></div>
        <div className="form-group"><label htmlFor="party-phone">Phone</label><input className="field" id="party-phone" name="phone" type="tel" maxLength={40} defaultValue={editing?.phone ?? ""} /></div>
        <div className="form-group full"><label htmlFor="party-address">Address</label><textarea className="textarea" id="party-address" name="address" maxLength={1000} defaultValue={editing?.address ?? ""} /></div>
        <fieldset className="form-group full"><legend>Relationship</legend><label className="step-up-check"><input type="checkbox" name="relationships" value="CUSTOMER" defaultChecked={selectedRelationships.has("CUSTOMER")} /> Customer</label><label className="step-up-check"><input type="checkbox" name="relationships" value="SUPPLIER" defaultChecked={selectedRelationships.has("SUPPLIER")} /> Supplier</label><span className="field-help">At least one relationship is required. Only active relationships can be used on new transactions.</span></fieldset>
        <div className="form-actions full"><button className="btn btn-primary" disabled={state.kind === "working"}>{state.kind === "working" ? "Saving…" : editing ? "Save changes" : "Create trading partner"}</button></div>
        {state.message ? <div className={`alert full ${state.kind === "error" ? "alert-error" : state.kind === "success" ? "alert-success" : "alert-info"}`} role={state.kind === "error" ? "alert" : "status"}>{state.message}</div> : null}
      </div>
    </form>
  </div>;
}
