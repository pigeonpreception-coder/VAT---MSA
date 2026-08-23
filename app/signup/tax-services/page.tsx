import type { Metadata } from "next";
import { chatGPTSignInPath } from "@/app/chatgpt-auth";

export const metadata: Metadata = { title: "Tax service access", description: "Government-authorized VAT-MSA tax access options." };

export default function TaxServicesSignupPage() {
  return <div className="signup-shell">
    <header className="signup-topbar"><a className="signup-brand" href="/signup"><span className="brand-mark" aria-hidden="true">V</span><span><strong>VAT-MSA</strong><small>Government tax access</small></span></a><span className="env-pill"><span className="pulse" /> Local staging</span></header>
    <main className="signup-main signup-choice-main">
      <section className="signup-choice-intro"><p className="eyebrow">Tax Authority / Taxpayer</p><h1>Access VAT-MSA Tax Services</h1><p className="signup-lead">Tax access is granted by the country’s Tax Governing Authority under its tax subscription and your taxpayer authorization. A commercial company plan cannot grant this access.</p></section>
      <section className="signup-choice-grid tax-option-grid">
        <article className="signup-choice-card"><span className="signup-choice-kicker">Namibia authority federation</span><h2>Sign in through ITAS</h2><p>The approved design links the ITAS subject to one canonical taxpayer identity.</p><button className="btn btn-secondary" type="button" disabled>ITAS integration disabled</button><small>Requires an approved NamRA/ITAS contract, keys and acceptance evidence.</small></article>
        <article className="signup-choice-card"><span className="signup-choice-kicker">Configured country authority</span><h2>Sign in through Tax Authority</h2><p>Country adapters are modular and disabled until the relevant authority approves them.</p><button className="btn btn-secondary" type="button" disabled>Authority adapter unavailable</button><small>No production authority connection is configured.</small></article>
        <article className="signup-choice-card"><span className="signup-choice-kicker">Controlled direct identity</span><h2>VAT-MSA direct access</h2><p>Direct sign-in still requires a pre-existing identity link and active taxpayer authorization.</p><a className="btn btn-primary" href={chatGPTSignInPath("/")}>Sign in to VAT-MSA</a><small>Sign-in does not create or authorize a taxpayer.</small></article>
      </section>
      <div className="signup-safety"><strong>No commercial purchase required</strong><p>A taxpayer does not buy the government tax plan. If tax authorization is absent, only the Tax Governing Authority can provision or restore it.</p></div>
    </main>
    <footer className="signup-footer"><a href="/signup">Choose another access path</a> · Live ITAS and authority adapters are disabled</footer>
  </div>;
}
