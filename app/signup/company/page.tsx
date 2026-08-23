import type { Metadata } from "next";
import { chatGPTSignInPath, getChatGPTUser } from "@/app/chatgpt-auth";
import { listPublicSignupPlans } from "@/lib/data/signup-repository";
import { SelfServeSignupForm } from "../SelfServeSignupForm";

export const metadata: Metadata = { title: "Company administrator subscription", description: "Submit a controlled commercial VAT-MSA application as the Company System Administrator." };
export const dynamic = "force-dynamic";

export default async function CompanySignupPage() {
  const [plans, identity] = await Promise.all([listPublicSignupPlans(), getChatGPTUser()]);
  return <div className="signup-shell">
    <header className="signup-topbar">
      <a className="signup-brand" href="/signup"><span className="brand-mark" aria-hidden="true">V</span><span><strong>VAT-MSA</strong><small>Company commercial onboarding</small></span></a>
      <div className="signup-top-actions"><span className="env-pill"><span className="pulse" /> Local staging</span>{!identity ? <a className="btn btn-secondary" href={chatGPTSignInPath("/signup/company")}>Assert administrator identity</a> : <span className="signup-identity">{identity.email}<small>Workspace identity asserted</small></span>}</div>
    </header>
    <main className="signup-main">
      <section className="signup-hero">
        <div><p className="eyebrow">Company SaaS subscription</p><h1>Start a controlled business-services application</h1><p className="signup-lead">For the Company’s System Administrator only. Submit the organisation for verification, select a configurable commercial plan without prices, and receive a traceable pending reference.</p><div className="signup-safety"><strong>Commercial authority only</strong><p>This application cannot grant VAT returns, taxpayer authorization, government roles or other Tax Authority functions. It does not charge or activate a licence locally.</p></div></div>
        <ol className="signup-journey" aria-label="Commercial signup journey"><li className="current"><span>1</span><div><strong>Administrator application</strong><p>Authority attestation and organisation details</p></div></li><li><span>2</span><div><strong>Verify administrator</strong><p>Organisation relationship and identity assurance</p></div></li><li><span>3</span><div><strong>Plan and capacity</strong><p>Commercial modules and explicit user capacity</p></div></li><li><span>4</span><div><strong>Controlled activation</strong><p>Payment and activation remain disabled locally</p></div></li></ol>
      </section>
      <SelfServeSignupForm plans={plans} assertedEmail={identity?.email ?? null} assertedName={identity?.fullName ?? null} />
    </main>
    <footer className="signup-footer"><a href="/signup">Choose another access path</a> · Local staging only · No payment or production activation</footer>
  </div>;
}
