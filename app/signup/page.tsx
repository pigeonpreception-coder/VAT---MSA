import type { Metadata } from "next";
import Link from "next/link";
import { chatGPTSignInPath, getChatGPTUser } from "@/app/chatgpt-auth";
import { listPublicSignupPlans } from "@/lib/data/signup-repository";
import { SelfServeSignupForm } from "./SelfServeSignupForm";

export const metadata: Metadata = {
  title: "Self-serve signup",
  description: "Apply for controlled VAT-MSA organisation access and a placeholder licence plan.",
};
export const dynamic = "force-dynamic";

export default async function SignupPage() {
  const [plans, identity] = await Promise.all([listPublicSignupPlans(), getChatGPTUser()]);

  return <div className="signup-shell">
    <header className="signup-topbar">
      <div className="signup-brand"><span className="brand-mark" aria-hidden="true">V</span><span><strong>VAT-MSA</strong><small>Controlled organisation onboarding</small></span></div>
      <div className="signup-top-actions">
        <span className="env-pill"><span className="pulse" /> Local staging</span>
        {!identity ? <Link className="btn btn-secondary" href={chatGPTSignInPath("/signup")}>Assert workspace identity</Link> : <span className="signup-identity">{identity.email}<small>Workspace identity asserted</small></span>}
      </div>
    </header>

    <main className="signup-main">
      <section className="signup-hero">
        <div>
          <p className="eyebrow">Self-serve signup</p>
          <h1>Start your controlled VAT-MSA application</h1>
          <p className="signup-lead">Submit your organisation and taxpayer identity for verification, choose a configurable placeholder plan, and receive a traceable application reference.</p>
          <div className="signup-safety"><strong>What this does not do</strong><p>It does not create a password, activate a taxpayer or organisation, start a subscription, charge a payment method, or call live ITAS services.</p></div>
        </div>
        <ol className="signup-journey" aria-label="Signup journey">
          <li className="current"><span>1</span><div><strong>Submit application</strong><p>Identity, organisation, plan and consent</p></div></li>
          <li><span>2</span><div><strong>Verify identity</strong><p>Approved identity assurance required</p></div></li>
          <li><span>3</span><div><strong>Verify taxpayer</strong><p>Authoritative ITAS/NamRA gate</p></div></li>
          <li><span>4</span><div><strong>Controlled activation</strong><p>Independent approved provisioning only</p></div></li>
        </ol>
      </section>

      <SelfServeSignupForm
        plans={plans}
        assertedEmail={identity?.email ?? null}
        assertedName={identity?.fullName ?? null}
      />
    </main>
    <footer className="signup-footer">VAT-MSA local staging · Synthetic testing only · Production activation is not authorised</footer>
  </div>;
}
