/**
 * Module 10 Phase C: SaaS provider onboarding. Pure validation plus the
 * conformance test harness's fixed, code-versioned check catalogue — no DB
 * access. Mirrors lib/domain/integration.ts's own local object/text/bounded
 * helpers rather than importing them (each domain file owns its own tiny
 * validation primitives, per this codebase's convention).
 */

export type SaasValidationMessage = { code: string; path: string; message: string };

export class SaasValidationError extends Error {
  readonly messages: SaasValidationMessage[];

  constructor(messages: SaasValidationMessage[]) {
    super("SaaS command failed validation.");
    this.name = "SaasValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new SaasValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: SaasValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: SaasValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

const PROVIDER_KEY_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;
const CAPABILITY_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const ENDPOINT_PATTERN = /^https:\/\/[^\s]{5,290}$/;
const CATEGORY_VALUES = new Set(["ACCOUNTING", "ERP", "PAYROLL", "BANKING", "LOGISTICS", "OTHER"]);

export type SaasApplicationSubmission = {
  name: string;
  description: string;
  requested_capabilities: string[];
  endpoint_reference: string;
};

export type SaasProviderRegistration = {
  schema_version: "1.0.0";
  provider_key: string;
  legal_name: string;
  contact_email: string;
  category: string;
  application: SaasApplicationSubmission;
};

/** RegisterProvider. No separate "create application" verb is named by the playbook — a provider registers with exactly one named application (the one it wants conformance-certified) in the same call, creating both the SaaSProvider and Application rows atomically. */
export function validateProviderRegistration(payload: unknown): SaasProviderRegistration {
  const input = object(payload);
  const messages: SaasValidationMessage[] = [];
  schema(input, messages);
  const providerKey = text(input.provider_key).toUpperCase();
  if (!PROVIDER_KEY_PATTERN.test(providerKey)) messages.push({ code: "PROVIDER_KEY_INVALID", path: "/provider_key", message: "provider_key must be 2 to 50 uppercase letters, numbers or underscores, starting with a letter." });
  const legalName = bounded(input.legal_name, "/legal_name", "Legal name", 3, 200, messages);
  const contactEmail = text(input.contact_email).toLowerCase();
  if (!EMAIL_PATTERN.test(contactEmail)) messages.push({ code: "EMAIL_INVALID", path: "/contact_email", message: "A valid contact email address is required." });
  const category = text(input.category).toUpperCase();
  if (!CATEGORY_VALUES.has(category)) messages.push({ code: "CATEGORY_INVALID", path: "/category", message: `category must be one of: ${[...CATEGORY_VALUES].join(", ")}.` });

  const appInput = input.application && typeof input.application === "object" && !Array.isArray(input.application) ? input.application as Record<string, unknown> : {};
  const name = bounded(appInput.name, "/application/name", "Application name", 3, 150, messages);
  const description = bounded(appInput.description, "/application/description", "Application description", 10, 2_000, messages);
  const capabilitiesInput = Array.isArray(appInput.requested_capabilities) ? appInput.requested_capabilities : [];
  if (capabilitiesInput.length === 0) messages.push({ code: "CAPABILITIES_REQUIRED", path: "/application/requested_capabilities", message: "At least one requested capability is required." });
  const requestedCapabilities = capabilitiesInput.map((value) => text(value).toUpperCase()).filter(Boolean);
  for (const capability of requestedCapabilities) {
    if (!CAPABILITY_PATTERN.test(capability)) messages.push({ code: "CAPABILITY_INVALID", path: "/application/requested_capabilities", message: `Capability "${capability}" must be 2 to 50 uppercase letters, numbers or underscores.` });
  }
  const endpointReference = text(appInput.endpoint_reference);
  if (!ENDPOINT_PATTERN.test(endpointReference)) messages.push({ code: "ENDPOINT_REFERENCE_INVALID", path: "/application/endpoint_reference", message: "endpoint_reference must be an https:// URL." });

  if (messages.length) throw new SaasValidationError(messages);
  return {
    schema_version: "1.0.0", provider_key: providerKey, legal_name: legalName, contact_email: contactEmail, category,
    application: { name, description, requested_capabilities: requestedCapabilities, endpoint_reference: endpointReference },
  };
}

/**
 * The full documented event catalogue (08-enterprise-architecture/event-catalog.csv)
 * a conformance submission's acknowledged_events is checked against — see
 * EVENT_CONTRACT_ACKNOWLEDGED below. Kept as a literal, code-versioned list
 * (not read from the CSV at runtime) so a change to the catalogue is a
 * deliberate, reviewed edit here, not a silent behavioural drift — the same
 * "don't let the mock drift from the documented contract" caution the
 * playbook's own Phase B watch-out names, applied to Phase C's harness.
 */
export const KNOWN_EVENT_CATALOG = new Set([
  "TaxpayerRegistered", "TaxpayerVerified", "UserCreated", "UserRoleChanged", "QuotationCreated", "QuotationAccepted",
  "InvoiceCreated", "InvoiceCertified", "InvoiceCancelled", "InvoiceCorrected", "VATTransactionCreated", "VATTransactionMatched",
  "VATTransactionExceptionDetected", "VATPeriodOpened", "VATPeriodClosed", "VATReturnGenerated", "VATReturnSubmitted",
  "AuditCaseCreated", "AuditCaseAssigned", "RefundReviewStarted", "DocumentUploaded", "SecurityThreatDetected", "IdentityLinked",
  "ConsentRevoked", "ReturnAcknowledged", "PaymentSettled", "SyncConflictDetected", "LicensePurchased", "LicenseActivated",
  "LicenseExpired", "LicenseSuspended", "LicenseUpgraded", "LicenseDowngraded", "OrganisationAdminCreated", "OrganisationAdminChanged",
  "EmployeeInvited", "EmployeeActivated", "EmployeeSuspended", "EmployeeTerminated", "RoleCreated", "RoleChanged",
  "PermissionGranted", "PermissionRevoked", "WorkflowCreated", "WorkflowPublished", "WorkflowChanged", "WorkflowRetired",
  "WorkflowDecisionRecorded", "AccessRequested", "AccessApproved", "AccessRejected", "AccessCertified", "SoDViolationDetected",
  "PrivilegedActionPerformed", "NavigationConfigurationChanged", "CountryPackVersionCreated", "CountryPackValidated",
  "CountryPackApproved", "CountryPackActivated", "CountryPackRejected", "JurisdictionResolved", "JurisdictionConflictDetected",
  "OrganisationJurisdictionMigrationRequested", "OrganisationJurisdictionMigrationCompleted", "CurrencyRatePublished",
  "ManualCurrencyRateApproved", "TaxRuleVersionSelected", "TaxDeterminationCompleted", "CountryReadinessStateChanged",
  "DocumentTemplatePublished", "BusinessCalendarPublished", "DataResidencyPolicyActivated", "SecurityProfileVersionCreated",
  "SecurityProfileValidated", "SecurityProfileApproved", "SecurityProfileActivated", "SecurityProfileRejected",
  "PrivilegedAccessRequested", "PrivilegedAccessGranted", "PrivilegedAccessExpired", "SecurityIncidentDeclared",
  "SecurityResponseActionExecuted", "DigitalEvidenceCollected", "PrivacyRightsRequestReceived", "PrivacyImpactAssessmentApproved",
  "VulnerabilityDetected", "VulnerabilityRemediated", "BackupRestoreTestCompleted", "SecurityControlStateChanged",
]);

export type SaasEnvironment = "SANDBOX" | "PRODUCTION";

export type ConformanceSubmission = {
  schema_version: "1.0.0";
  environment: SaasEnvironment;
  tested_capabilities: string[];
  acknowledged_events: string[];
};

export function validateConformanceSubmission(payload: unknown): ConformanceSubmission {
  const input = object(payload);
  const messages: SaasValidationMessage[] = [];
  schema(input, messages);
  const environment = text(input.environment).toUpperCase();
  if (environment !== "SANDBOX" && environment !== "PRODUCTION") messages.push({ code: "ENVIRONMENT_INVALID", path: "/environment", message: "environment must be SANDBOX or PRODUCTION." });
  const testedInput = Array.isArray(input.tested_capabilities) ? input.tested_capabilities : [];
  if (testedInput.length === 0) messages.push({ code: "CAPABILITIES_REQUIRED", path: "/tested_capabilities", message: "At least one tested capability is required." });
  const testedCapabilities = testedInput.map((value) => text(value).toUpperCase()).filter(Boolean);
  const eventsInput = Array.isArray(input.acknowledged_events) ? input.acknowledged_events : [];
  if (eventsInput.length === 0) messages.push({ code: "EVENTS_REQUIRED", path: "/acknowledged_events", message: "At least one acknowledged event is required." });
  const acknowledgedEvents = eventsInput.map((value) => text(value)).filter(Boolean);
  if (messages.length) throw new SaasValidationError(messages);
  return { schema_version: "1.0.0", environment: environment as SaasEnvironment, tested_capabilities: testedCapabilities, acknowledged_events: acknowledgedEvents };
}

export type ConformanceCheck = { code: string; status: "PASS" | "FAIL"; rationale: string };

export const CONFORMANCE_SUITE_VERSION = "1.0";

const RESTRICTED_DATA_CLASSIFICATIONS = new Set(["TAX_CONFIDENTIAL", "RESTRICTED"]);

/**
 * The conformance test harness itself — a fixed, code-versioned catalogue
 * of explainable checks (mirrors Module 9 Phase B's refund_claim_checks
 * pattern: named, deterministic, PASS/FAIL with a rationale, never a
 * black-box composite score), evaluated purely from data already on hand
 * (the application's own registration, the submission, and — for
 * SANDBOX_PRECEDES_PRODUCTION only — whether a prior PASSED sandbox run
 * exists, passed in by the repository since that's the one check needing
 * a DB read). "Conformance test harness built ahead of any specific
 * provider signing" (the playbook's own words): this evaluates real
 * structural conformance today, with no live endpoint ever actually
 * called — there is nothing to call.
 */
export function evaluateConformance(
  application: { requested_capabilities: string[] },
  submission: ConformanceSubmission,
  priorSandboxPassed: boolean,
): ConformanceCheck[] {
  const checks: ConformanceCheck[] = [];

  const unrequested = submission.tested_capabilities.filter((capability) => !application.requested_capabilities.includes(capability));
  checks.push(unrequested.length === 0
    ? { code: "CAPABILITY_SCOPE_MATCHED", status: "PASS", rationale: "Every tested capability was declared at registration." }
    : { code: "CAPABILITY_SCOPE_MATCHED", status: "FAIL", rationale: `Tested capabilities exceed what was registered: ${unrequested.join(", ")}.` });

  const unknownEvents = submission.acknowledged_events.filter((event) => !KNOWN_EVENT_CATALOG.has(event));
  checks.push(unknownEvents.length === 0
    ? { code: "EVENT_CONTRACT_ACKNOWLEDGED", status: "PASS", rationale: `All ${submission.acknowledged_events.length} acknowledged event(s) match the documented event catalogue.` }
    : { code: "EVENT_CONTRACT_ACKNOWLEDGED", status: "FAIL", rationale: `Unrecognised event name(s), not present in the documented catalogue: ${unknownEvents.join(", ")}.` });

  const restrictedRequested = submission.tested_capabilities.some((capability) => RESTRICTED_DATA_CLASSIFICATIONS.has(capability));
  checks.push(submission.environment === "PRODUCTION" || !restrictedRequested
    ? { code: "DATA_CLASSIFICATION_BOUNDED", status: "PASS", rationale: "No restricted-tier data classification requested for a SANDBOX submission." }
    : { code: "DATA_CLASSIFICATION_BOUNDED", status: "FAIL", rationale: "SANDBOX conformance may not request a TAX_CONFIDENTIAL/RESTRICTED-tier capability." });

  checks.push(submission.environment === "SANDBOX" || priorSandboxPassed
    ? { code: "SANDBOX_PRECEDES_PRODUCTION", status: "PASS", rationale: submission.environment === "SANDBOX" ? "Not applicable to a SANDBOX submission." : "A prior PASSED SANDBOX conformance run exists for this application." }
    : { code: "SANDBOX_PRECEDES_PRODUCTION", status: "FAIL", rationale: "A PRODUCTION conformance submission requires a prior PASSED SANDBOX run for the same application." });

  return checks;
}

export function conformanceOutcome(checks: ConformanceCheck[]): "PASSED" | "FAILED" {
  return checks.every((check) => check.status === "PASS") ? "PASSED" : "FAILED";
}
