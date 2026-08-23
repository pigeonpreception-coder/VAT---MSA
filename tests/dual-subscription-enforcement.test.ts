import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

function source(...parts: string[]) {
  return readFileSync(join(process.cwd(), ...parts), "utf8");
}

describe("central dual-authority enforcement", () => {
  it("routes government and commercial features to independent decision paths", () => {
    const repository = source("lib", "data", "licensing-repository.ts");
    const governmentStart = repository.indexOf("async function assertGovernmentTaxFeatureOperation");
    const platformStart = repository.indexOf("async function assertPlatformControlOperation");
    const commercialStart = repository.indexOf("export async function assertLicensedFeatureOperation");
    const permissionStart = repository.indexOf("export async function requireLicensedPermission");
    const government = repository.slice(governmentStart, platformStart);
    const commercial = repository.slice(commercialStart, permissionStart);
    expect(government).toContain("tax_subscriptions");
    expect(government).toContain("taxpayer_authorizations");
    expect(government).not.toContain("getOrganisationLicense");
    expect(commercial).toContain("getOrganisationLicense");
    expect(commercial).toContain('feature.authority_domain !== "COMMERCIAL_SAAS"');
    expect(repository).toContain('policy.authority_domain === "GOVERNMENT_TAX"');
    expect(repository).toContain('policy.authority_domain === "PLATFORM_CONTROL"');
  });

  it("keeps the synthetic commercial plan free of government tax entitlements", () => {
    const runtime = source("db", "runtime.ts");
    expect(runtime).toContain("'plan-pilot-professional-v1','PILOT_PROFESSIONAL','Professional Pilot',1,'COMMERCIAL_SAAS'");
    expect(runtime).not.toContain("'ent-core','plan-pilot-professional-v1','CORE_VAT'");
    expect(runtime).toContain("'ent-tax-core','plan-tax-na-synthetic-v1','CORE_VAT'");
    expect(runtime).toContain("'tax-authz-org1'");
    expect(runtime).toContain("'LOCAL_STAGING'");
  });

  it("offers only commercial plans and requires company-admin attestation", () => {
    const repository = source("lib", "data", "signup-repository.ts");
    const domain = source("lib", "domain", "signup.ts");
    const route = source("app", "api", "v1", "signup-applications", "route.ts");
    expect(repository.match(/plan_domain='COMMERCIAL_SAAS'/g)?.length).toBeGreaterThanOrEqual(2);
    expect(domain).toContain("company_system_administrator_attested");
    expect(domain).toContain("COMPANY_ADMIN_AUTHORITY_REQUIRED");
    expect(route).toContain('problem(403, "COMPANY_ADMIN_AUTHORITY_REQUIRED"');
  });

  it("keeps tax, company and employee public onboarding paths visibly separate", () => {
    const landing = source("app", "signup", "page.tsx");
    const tax = source("app", "signup", "tax-services", "page.tsx");
    const company = source("app", "signup", "company", "page.tsx");
    const employee = source("app", "signup", "employee", "page.tsx");
    expect(landing).toContain("/signup/tax-services");
    expect(landing).toContain("/signup/company");
    expect(landing).toContain("/signup/employee");
    expect(tax).toContain("ITAS integration disabled");
    expect(company).toContain("Company’s System Administrator only");
    expect(employee).not.toContain("SelfServeSignupForm");
    expect(employee).toContain("Employees cannot create the organisation or subscription");
  });
});
