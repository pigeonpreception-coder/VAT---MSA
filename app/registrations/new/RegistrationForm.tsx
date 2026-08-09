"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

type Problem = { detail?: string; errors?: Array<{ path?: string; message?: string }> };
type Accepted = { registration_id: string; status: string; verification_status: string; next_action: string };

export function RegistrationForm() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [accepted, setAccepted] = useState<Accepted | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    setAccepted(null);
    const form = new FormData(event.currentTarget);
    const body = {
      schema_version: "1.0.0",
      vat_number: String(form.get("vat_number") ?? ""),
      tin: String(form.get("tin") ?? ""),
      company_registration_number: String(form.get("company_registration_number") ?? "") || undefined,
      legal_name: String(form.get("legal_name") ?? ""),
      trading_name: String(form.get("trading_name") ?? "") || undefined,
      taxpayer_type: String(form.get("taxpayer_type") ?? ""),
      return_frequency: String(form.get("return_frequency") ?? ""),
      address: String(form.get("address") ?? ""),
      email: String(form.get("email") ?? ""),
    };
    try {
      const response = await fetch("/api/v1/registration-applications", {
        method: "POST",
        headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
        body: JSON.stringify(body),
      });
      const result = await response.json() as Accepted & Problem;
      if (!response.ok) {
        const fields = result.errors?.map((item) => item.message).filter(Boolean).join(" ");
        throw new Error(fields || result.detail || "The application could not be accepted.");
      }
      setAccepted(result);
      event.currentTarget.reset();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "The application could not be accepted.");
    } finally {
      setBusy(false);
    }
  }

  return <section className="panel registration-form-panel">
    <div className="panel-head"><div><h2 className="panel-title">Legal identity and filing profile</h2><div className="panel-meta">All fields are validated server-side and recorded with correlation and audit evidence</div></div></div>
    <div className="panel-body">
      {error ? <div className="alert alert-error" role="alert">{error}</div> : null}
      {accepted ? <div className="alert alert-success" role="status"><strong>Application accepted: <span className="mono">{accepted.registration_id}</span></strong><br />{accepted.next_action}<div className="form-success-actions"><Link className="btn btn-secondary" href="/registrations">View registration intake</Link></div></div> : null}
      <form className="form-grid registration-form" onSubmit={submit}>
        <h3 className="section-title">Authoritative identifiers</h3>
        <div className="form-group"><label htmlFor="vat_number">VAT number</label><input className="field" id="vat_number" name="vat_number" maxLength={40} required autoComplete="off" /><span className="field-help">Used for duplicate detection; authoritative format remains subject to ITAS confirmation.</span></div>
        <div className="form-group"><label htmlFor="tin">TIN</label><input className="field" id="tin" name="tin" maxLength={40} required autoComplete="off" /></div>
        <div className="form-group full"><label htmlFor="company_registration_number">Company registration number</label><input className="field" id="company_registration_number" name="company_registration_number" maxLength={40} autoComplete="off" /></div>
        <h3 className="section-title">Legal entity</h3>
        <div className="form-group"><label htmlFor="legal_name">Legal name</label><input className="field" id="legal_name" name="legal_name" maxLength={200} required autoComplete="organization" /></div>
        <div className="form-group"><label htmlFor="trading_name">Trading name</label><input className="field" id="trading_name" name="trading_name" maxLength={200} /></div>
        <div className="form-group"><label htmlFor="taxpayer_type">Taxpayer type</label><select className="select" id="taxpayer_type" name="taxpayer_type" defaultValue="PRIVATE_COMPANY" required><option value="PRIVATE_COMPANY">Private company</option><option value="CLOSE_CORPORATION">Close corporation</option><option value="SOLE_PROPRIETOR">Sole proprietor</option><option value="PARTNERSHIP">Partnership</option><option value="TRUST">Trust</option><option value="NON_PROFIT">Non-profit</option><option value="PUBLIC_ENTITY">Public entity</option><option value="OTHER">Other</option></select></div>
        <div className="form-group"><label htmlFor="return_frequency">Expected return frequency</label><select className="select" id="return_frequency" name="return_frequency" defaultValue="BIMONTHLY" required><option value="MONTHLY">Monthly</option><option value="BIMONTHLY">Bi-monthly</option><option value="QUARTERLY">Quarterly</option><option value="ANNUAL">Annual</option></select></div>
        <div className="form-group full"><label htmlFor="address">Registered address</label><textarea className="textarea" id="address" name="address" rows={3} maxLength={500} required autoComplete="street-address" /></div>
        <div className="form-group full"><label htmlFor="email">Official contact email</label><input className="field" type="email" id="email" name="email" maxLength={254} required autoComplete="email" /></div>
        <div className="form-group full form-actions"><button className="btn btn-primary" type="submit" disabled={busy}>{busy ? "Submitting…" : "Submit for verification"}</button><Link className="btn btn-secondary" href="/registrations">Cancel</Link></div>
      </form>
    </div>
  </section>;
}
