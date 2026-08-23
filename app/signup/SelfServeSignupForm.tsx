"use client";

import { useRef, useState, type FormEvent } from "react";
import type { PublicSignupPlan, SelfServeSignupAccepted } from "@/lib/data/signup-repository";
import {
  SELF_SERVE_PRIVACY_NOTICE_VERSION,
  SELF_SERVE_TERMS_VERSION,
} from "@/lib/domain/signup";

type Problem = { detail?: string; errors?: Array<{ path?: string; message?: string }> };

export function SelfServeSignupForm({
  plans,
  assertedEmail,
  assertedName,
}: {
  plans: PublicSignupPlan[];
  assertedEmail: string | null;
  assertedName: string | null;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [accepted, setAccepted] = useState<SelfServeSignupAccepted | null>(null);
  const idempotencyKey = useRef<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formElement = event.currentTarget;
    setBusy(true);
    setError("");
    setAccepted(null);
    const form = new FormData(formElement);
    const body = {
      schema_version: "1.0.0",
      applicant_name: String(form.get("applicant_name") ?? ""),
      applicant_role: String(form.get("applicant_role") ?? ""),
      contact_email: String(form.get("contact_email") ?? ""),
      country_code: "NA",
      plan_code: String(form.get("plan_code") ?? ""),
      vat_number: String(form.get("vat_number") ?? ""),
      tin: String(form.get("tin") ?? ""),
      company_registration_number: String(form.get("company_registration_number") ?? "") || undefined,
      legal_name: String(form.get("legal_name") ?? ""),
      trading_name: String(form.get("trading_name") ?? "") || undefined,
      taxpayer_type: String(form.get("taxpayer_type") ?? ""),
      return_frequency: String(form.get("return_frequency") ?? ""),
      address: String(form.get("address") ?? ""),
      company_system_administrator_attested: form.get("company_system_administrator_attested") === "on",
      terms_accepted: form.get("terms_accepted") === "on",
      privacy_notice_accepted: form.get("privacy_notice_accepted") === "on",
    };
    idempotencyKey.current ??= crypto.randomUUID();
    try {
      const response = await fetch("/api/v1/signup-applications", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": idempotencyKey.current,
        },
        body: JSON.stringify(body),
      });
      const result = await response.json() as SelfServeSignupAccepted & Problem;
      if (!response.ok) {
        const fields = result.errors?.map((item) => item.message).filter(Boolean).join(" ");
        throw new Error(fields || result.detail || "The signup application could not be accepted.");
      }
      setAccepted(result);
      idempotencyKey.current = null;
      formElement.reset();
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "The signup application could not be accepted.");
    } finally {
      setBusy(false);
    }
  }

  if (!plans.length) {
    return <div className="signup-unavailable" role="status">
      <strong>Self-serve applications are temporarily unavailable.</strong>
      No approved placeholder licence plan is currently open for selection. No payment or licence activation has occurred.
    </div>;
  }

  return <div className="signup-form-card">
    <div className="signup-form-head">
      <span className="signup-step">Application</span>
      <h2>Tell us about you and the organisation</h2>
      <p>Fields are checked server-side. Your submission becomes a pending verification record, not an active account.</p>
    </div>
    {error ? <div className="alert alert-error" role="alert">{error}</div> : null}
    {accepted ? <div className="alert alert-success signup-success" role="status">
      <strong>Application received</strong>
      <span className="signup-reference">{accepted.application_reference}</span>
      <p>{accepted.next_action}</p>
      <div className="signup-state-row">
        <span>{accepted.identity_status.replaceAll("_", " ")}</span>
        <span>{accepted.taxpayer_verification_status.replaceAll("_", " ")}</span>
        <span>{accepted.licence_status.replaceAll("_", " ")}</span>
      </div>
    </div> : null}
    <form className="form-grid registration-form signup-form" onSubmit={submit}>
      <h3 className="section-title">Applicant</h3>
      <div className="form-group">
        <label htmlFor="applicant_name">Full name</label>
        <input className="field" id="applicant_name" name="applicant_name" defaultValue={assertedName ?? ""} minLength={2} maxLength={120} required autoComplete="name" />
      </div>
      <div className="form-group">
        <label htmlFor="applicant_role">Authority</label>
        <select className="select" id="applicant_role" name="applicant_role" defaultValue="OWNER" required>
          <option value="OWNER">Owner</option>
          <option value="DIRECTOR">Director</option>
          <option value="PARTNER">Partner</option>
          <option value="TRUSTEE">Trustee</option>
          <option value="AUTHORISED_REPRESENTATIVE">Authorised representative</option>
        </select>
      </div>
      <div className="form-group full">
        <label htmlFor="contact_email">Contact email</label>
        <input className="field" type="email" id="contact_email" name="contact_email" defaultValue={assertedEmail ?? ""} readOnly={Boolean(assertedEmail)} maxLength={254} required autoComplete="email" />
        <span className="field-help">{assertedEmail ? "Matched to the asserted workspace identity. This does not provision VAT-MSA access." : "Identity verification is required before an administrator can be provisioned."}</span>
      </div>

      <h3 className="section-title">Organisation and taxpayer identity</h3>
      <div className="form-group">
        <label htmlFor="legal_name">Legal name</label>
        <input className="field" id="legal_name" name="legal_name" minLength={2} maxLength={200} required autoComplete="organization" />
      </div>
      <div className="form-group">
        <label htmlFor="trading_name">Trading name <span className="muted">(optional)</span></label>
        <input className="field" id="trading_name" name="trading_name" minLength={2} maxLength={200} />
      </div>
      <div className="form-group">
        <label htmlFor="vat_number">VAT number</label>
        <input className="field" id="vat_number" name="vat_number" minLength={3} maxLength={40} required autoComplete="off" />
        <span className="field-help">Used for duplicate detection; authoritative verification remains gated.</span>
      </div>
      <div className="form-group">
        <label htmlFor="tin">TIN</label>
        <input className="field" id="tin" name="tin" minLength={3} maxLength={40} required autoComplete="off" />
      </div>
      <div className="form-group full">
        <label htmlFor="company_registration_number">Company registration number <span className="muted">(optional)</span></label>
        <input className="field" id="company_registration_number" name="company_registration_number" minLength={3} maxLength={40} autoComplete="off" />
      </div>
      <div className="form-group">
        <label htmlFor="taxpayer_type">Taxpayer type</label>
        <select className="select" id="taxpayer_type" name="taxpayer_type" defaultValue="PRIVATE_COMPANY" required>
          <option value="PRIVATE_COMPANY">Private company</option>
          <option value="CLOSE_CORPORATION">Close corporation</option>
          <option value="SOLE_PROPRIETOR">Sole proprietor</option>
          <option value="PARTNERSHIP">Partnership</option>
          <option value="TRUST">Trust</option>
          <option value="NON_PROFIT">Non-profit</option>
          <option value="PUBLIC_ENTITY">Public entity</option>
          <option value="OTHER">Other</option>
        </select>
      </div>
      <div className="form-group">
        <label htmlFor="return_frequency">Expected return frequency</label>
        <select className="select" id="return_frequency" name="return_frequency" defaultValue="BIMONTHLY" required>
          <option value="MONTHLY">Monthly</option>
          <option value="BIMONTHLY">Bi-monthly</option>
          <option value="QUARTERLY">Quarterly</option>
          <option value="ANNUAL">Annual</option>
        </select>
      </div>
      <div className="form-group full">
        <label htmlFor="address">Registered address</label>
        <textarea className="textarea" id="address" name="address" rows={3} minLength={5} maxLength={500} required autoComplete="street-address" />
      </div>

      <h3 className="section-title">Placeholder licence plan</h3>
      <div className="form-group full">
        <label htmlFor="plan_code">Plan</label>
        <select className="select" id="plan_code" name="plan_code" defaultValue={plans[0]?.code} required>
          {plans.map((plan) => <option key={`${plan.code}-${plan.version}`} value={plan.code}>{plan.name}</option>)}
        </select>
        <span className="field-help">No prices or payment details are collected. Selection records your requested plan only.</span>
      </div>
      <div className="signup-plan-list full" aria-label="Available plan capabilities">
        {plans.map((plan) => <article key={`${plan.code}-features-${plan.version}`}>
          <strong>{plan.name}</strong>
          <span>Configurable placeholder · version {plan.version}</span>
          <p>{plan.features.length ? plan.features.slice(0, 6).join(" · ") : "Entitlements remain configurable."}</p>
        </article>)}
      </div>

      <h3 className="section-title">Authority and consent</h3>
      <label className="signup-check full">
        <input type="checkbox" name="company_system_administrator_attested" required />
        <span>I confirm that I am the Company System Administrator authorised to start this commercial subscription application. Ordinary employees must use an invitation.</span>
      </label>
      <label className="signup-check full">
        <input type="checkbox" name="terms_accepted" required />
        <span>I accept the controlled pilot terms, version {SELF_SERVE_TERMS_VERSION}.</span>
      </label>
      <label className="signup-check full">
        <input type="checkbox" name="privacy_notice_accepted" required />
        <span>I acknowledge the privacy notice, version {SELF_SERVE_PRIVACY_NOTICE_VERSION}.</span>
      </label>
      <div className="form-group full form-actions signup-actions">
        <button className="btn btn-primary" type="submit" disabled={busy}>{busy ? "Submitting securely…" : "Submit signup application"}</button>
        <span className="field-help">Submission never triggers a real payment, email, SMS, ITAS action, account or licence activation.</span>
      </div>
    </form>
  </div>;
}
