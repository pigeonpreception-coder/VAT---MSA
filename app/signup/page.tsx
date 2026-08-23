import type { Metadata } from "next";
import { chatGPTSignInPath, getChatGPTUser } from "@/app/chatgpt-auth";

export const metadata: Metadata = { title: "Choose VAT-MSA access", description: "Choose government tax access, company SaaS subscription, or employee invitation access." };
export const dynamic = "force-dynamic";

export default async function SignupPage() {
  const identity = await getChatGPTUser();
  return <div className="signup-shell">
    <header className="signup-topbar">
      <div className="signup-brand"><span className="brand-mark" aria-hidden="true">V</span><span><strong>VAT-MSA</strong><small>Dual-authority access</small></span></div>
      <div className="signup-top-actions"><span className="env-pill"><span className="pulse" /> Local staging</span>{identity ? <span className="signup-identity">{identity.email}<small>Workspace identity asserted</small></span> : <a className="btn btn-secondary" href={chatGPTSignInPath("/signup")}>Sign in</a>}</div>
    </header>
    <main className="signup-main signup-choice-main">
      <section className="signup-choice-intro"><p className="eyebrow">Choose the correct authority path</p><h1>How do you need to access VAT-MSA?</h1><p className="signup-lead">Government tax services and company business services use independent subscriptions. Neither path grants the other domain.</p></section>
      <section className="signup-choice-grid" aria-label="VAT-MSA access paths">
        <article className="signup-choice-card tax-path"><span className="signup-choice-kicker">Tax Authority / Taxpayer</span><h2>Access VAT-MSA Tax Services</h2><p>Use tax functions authorized by the country Tax Governing Authority. A taxpayer does not purchase the government tax subscription.</p><ul><li>Tax authority or ITAS identity</li><li>Active taxpayer authorization</li><li>Tax functions only</li></ul><a className="btn btn-primary" href="/signup/tax-services">View tax access options</a></article>
        <article className="signup-choice-card commercial-path"><span className="signup-choice-kicker">Company SaaS</span><h2>Subscribe to Business Services</h2><p>Only the verified Company System Administrator may start an organisation’s commercial subscription application.</p><ul><li>Accounting and expenses</li><li>Inventory, projects and workflows</li><li>Explicit employee capacity</li></ul><a className="btn btn-primary" href="/signup/company">Company Administrator — Start Subscription</a></article>
        <article className="signup-choice-card employee-path"><span className="signup-choice-kicker">Company employee</span><h2>Join an Existing Organisation</h2><p>Employees cannot create an organisation or purchase a company licence. Use your administrator-issued invitation or sign in.</p><ul><li>Accept organisation invitation</li><li>Use assigned roles and scope</li><li>No subscription authority</li></ul><a className="btn btn-secondary" href="/signup/employee">Employee access</a></article>
      </section>
      <div className="signup-safety"><strong>Local/staging safety boundary</strong><p>Real payment, live ITAS, email, SMS, production tax activation and unapproved statutory rules are disabled. All displayed authority and subscription data is synthetic.</p></div>
    </main>
    <footer className="signup-footer">VAT-MSA local staging · Synthetic testing only · Production activation is not authorised</footer>
  </div>;
}
