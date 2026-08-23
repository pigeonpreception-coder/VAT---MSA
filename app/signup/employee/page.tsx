import type { Metadata } from "next";
import { chatGPTSignInPath } from "@/app/chatgpt-auth";

export const metadata: Metadata = { title: "Employee access", description: "Sign in or accept an invitation to an existing VAT-MSA organisation." };

export default function EmployeeSignupPage() {
  return <div className="signup-shell">
    <header className="signup-topbar"><a className="signup-brand" href="/signup"><span className="brand-mark" aria-hidden="true">V</span><span><strong>VAT-MSA</strong><small>Employee invitation access</small></span></a><span className="env-pill"><span className="pulse" /> Local staging</span></header>
    <main className="signup-main signup-choice-main">
      <section className="signup-choice-intro"><p className="eyebrow">Existing organisation</p><h1>Join with an administrator invitation</h1><p className="signup-lead">Your Company System Administrator creates the employee record, reserves an available licence seat and issues an expiring invitation. Employees cannot create the organisation or subscription.</p></section>
      <section className="signup-choice-grid employee-option-grid">
        <article className="signup-choice-card"><span className="signup-choice-kicker">Already provisioned</span><h2>Sign in</h2><p>Use the identity your organisation administrator linked to your VAT-MSA membership.</p><a className="btn btn-primary" href={chatGPTSignInPath("/")}>Sign in</a></article>
        <article className="signup-choice-card"><span className="signup-choice-kicker">Invitation required</span><h2>Accept an invitation</h2><p>Open the single-use invitation supplied by your administrator. Local/staging email and SMS delivery are disabled, so synthetic invitations are managed inside the administration workspace.</p><button className="btn btn-secondary" type="button" disabled>No invitation token supplied</button></article>
      </section>
      <div className="signup-safety"><strong>Need access?</strong><p>Contact your Company’s System Administrator. VAT-MSA will not create a company or sell a licence from the employee path.</p></div>
    </main>
    <footer className="signup-footer"><a href="/signup">Choose another access path</a> · Employee access never grants subscription authority</footer>
  </div>;
}
