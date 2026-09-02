import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import {
  acceptQuotation,
  approveExpense,
  approveProjectBudget,
  BusinessResourceError,
  closeAccountingPeriod,
  convertQuotationToInvoice,
  createAccount,
  createBusinessParty,
  createExpense,
  createExpenseCategory,
  createProduct,
  createProject,
  createQuotation,
  createWarehouse,
  getBusinessPlatformSnapshot,
  getExpenseReport,
  getFinancialStatements,
  getInventoryAvailability,
  getInventoryValuation,
  getProjectProfitability,
  getSupplierVerificationHistory,
  getTrialBalance,
  expireQuotation,
  linkExpenseReceipt,
  postJournal,
  postProjectCost,
  recordStockMovement,
  rejectExpense,
  rejectQuotation,
  reverseJournalEntry,
  deactivateBusinessParty,
  searchBusinessParties,
  searchQuotations,
  sendQuotation,
  submitExpense,
  transferStock,
  updateBusinessParty,
  updateQuotation,
  verifySupplier,
} from "@/lib/data/business-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { BusinessValidationError } from "@/lib/domain/business";
import { CounterpartyTrustValidationError } from "@/lib/domain/counterparty-trust";
import { InvoiceValidationError } from "@/lib/domain/invoice";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type BusinessSection = "parties" | "quotations" | "journals" | "expenses" | "balances" | "projects" | "accounts" | "categories" | "products" | "warehouses";
export type BusinessCommand = "CREATE_BUSINESS_PARTY" | "UPDATE_BUSINESS_PARTY" | "DEACTIVATE_BUSINESS_PARTY" | "CREATE_QUOTATION" | "UPDATE_QUOTATION" | "SEND_QUOTATION" | "ACCEPT_QUOTATION" | "REJECT_QUOTATION" | "EXPIRE_QUOTATION" | "CONVERT_QUOTATION" | "POST_JOURNAL" | "CREATE_EXPENSE" | "RECORD_STOCK_MOVEMENT" | "CREATE_PROJECT" | "CREATE_ACCOUNT" | "REVERSE_JOURNAL_ENTRY" | "CLOSE_ACCOUNTING_PERIOD" | "CREATE_EXPENSE_CATEGORY" | "SUBMIT_EXPENSE" | "APPROVE_EXPENSE" | "REJECT_EXPENSE" | "APPROVE_PROJECT_BUDGET" | "POST_PROJECT_COST" | "VERIFY_SUPPLIER" | "CREATE_PRODUCT" | "CREATE_WAREHOUSE" | "TRANSFER_STOCK";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title,
    status,
    code,
    detail,
    correlationId,
    ...(errors ? { errors } : {}),
  }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

function requestedOrganisation(request: Request) {
  const value = new URL(request.url).searchParams.get("organisation_id")?.trim();
  return value || null;
}

async function businessLimits(actorId: string, tenant: string, command: string) {
  await enforceRateLimits([
    { key: `business:${command}:actor:${actorId}`, limit: 60, windowSeconds: 60 },
    { key: `business:${command}:tenant:${tenant}`, limit: 300, windowSeconds: 60 },
    { key: `business:${command}:global`, limit: 2_000, windowSeconds: 60 },
  ]);
}

export async function handleBusinessGet(request: Request, permission: string, section: BusinessSection) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    const organisationId = requestedOrganisation(request);
    await requireLicensedPermission(user, permission, { operationClass: "READ", requestedOrganisationId: organisationId });
    const snapshot = await getBusinessPlatformSnapshot(user, organisationId);
    return Response.json({ organisation: snapshot.organisation, [section]: snapshot[section] }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The requested business records are temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase A SearchCustomers/SearchSuppliers over the shared business_parties model — filter by relationship for either. */
export async function handlePartySearch(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "parties:manage");
    const result = await searchBusinessParties(user, requestedOrganisation(request), new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The party search is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase A: a supplier's verification history, most recent first. */
export async function handleSupplierVerificationHistory(request: Request, partyId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "parties:manage");
    const history = await getSupplierVerificationHistory(partyId, user, requestedOrganisation(request));
    return Response.json(history, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The supplier verification history is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase B SearchQuotes. */
export async function handleQuotationSearch(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "commercial:read");
    const result = await searchQuotations(user, requestedOrganisation(request), new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The quotation search is temporarily unavailable.", context.correlationId);
  }
}

const REPORT_DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/** Module 5 Phase C TrialBalance. as_of defaults to today when omitted. */
export async function handleTrialBalance(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "accounting:read");
    const asOfRaw = new URL(request.url).searchParams.get("as_of")?.trim();
    if (asOfRaw && !REPORT_DATE_PATTERN.test(asOfRaw)) return problem(422, "VALIDATION_FAILED", "Validation failed", "as_of must be an ISO date (YYYY-MM-DD).", context.correlationId);
    const trialBalance = await getTrialBalance(user, requestedOrganisation(request), asOfRaw);
    return Response.json(trialBalance, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The trial balance is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase C Statements (income statement + simplified balance sheet). from/to default to the current calendar month. */
export async function handleFinancialStatements(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "accounting:read");
    const url = new URL(request.url);
    const today = new Date().toISOString().slice(0, 10);
    const from = url.searchParams.get("from")?.trim() || `${today.slice(0, 7)}-01`;
    const to = url.searchParams.get("to")?.trim() || today;
    if (!REPORT_DATE_PATTERN.test(from) || !REPORT_DATE_PATTERN.test(to)) return problem(422, "VALIDATION_FAILED", "Validation failed", "from/to must be ISO dates (YYYY-MM-DD).", context.correlationId);
    if (to < from) return problem(422, "VALIDATION_FAILED", "Validation failed", "to cannot be earlier than from.", context.correlationId);
    const statements = await getFinancialStatements(user, requestedOrganisation(request), from, to);
    return Response.json(statements, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The financial statements are temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase E ExpenseReport. from/to default to the current calendar month, matching Statements' convention. */
export async function handleExpenseReport(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "expenses:read");
    const url = new URL(request.url);
    const today = new Date().toISOString().slice(0, 10);
    const from = url.searchParams.get("from")?.trim() || `${today.slice(0, 7)}-01`;
    const to = url.searchParams.get("to")?.trim() || today;
    if (!REPORT_DATE_PATTERN.test(from) || !REPORT_DATE_PATTERN.test(to)) return problem(422, "VALIDATION_FAILED", "Validation failed", "from/to must be ISO dates (YYYY-MM-DD).", context.correlationId);
    if (to < from) return problem(422, "VALIDATION_FAILED", "Validation failed", "to cannot be earlier than from.", context.correlationId);
    const report = await getExpenseReport(user, requestedOrganisation(request), from, to);
    return Response.json(report, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The expense report is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase E ProfitabilityReport for a single project. */
export async function handleProjectProfitability(request: Request, projectId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "projects:read");
    const report = await getProjectProfitability(projectId, user, requestedOrganisation(request));
    return Response.json(report, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The project profitability report is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase D GetAvailability: aggregated on-hand quantity per product, with a per-warehouse breakdown. Optional product_id/warehouse_id filters. */
export async function handleInventoryAvailability(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "inventory:read");
    const result = await getInventoryAvailability(user, requestedOrganisation(request), new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The inventory availability report is temporarily unavailable.", context.correlationId);
  }
}

/** Module 5 Phase D Valuation: on-hand quantity valued at weighted-average cost, per product and as an organisation-wide grand total. */
export async function handleInventoryValuation(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "inventory:read");
    const result = await getInventoryValuation(user, requestedOrganisation(request), new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The inventory valuation report is temporarily unavailable.", context.correlationId);
  }
}

export async function handleBusinessPost(request: Request, permission: string, command: BusinessCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    const organisationId = requestedOrganisation(request);
    await requireLicensedPermission(user, permission, { requestedOrganisationId: organisationId });
    await businessLimits(user.userId, user.taxpayerId ?? `role:${user.role}`, command);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    let resource: Record<string, unknown> | null;
    if (command === "ACCEPT_QUOTATION" || command === "EXPIRE_QUOTATION" || command === "SEND_QUOTATION") {
      if (!resourceId) throw new BusinessResourceError("Quotation id is required.", 400);
      resource = command === "ACCEPT_QUOTATION"
        ? await acceptQuotation(resourceId, user, idempotencyKey, context.correlationId, organisationId)
        : command === "EXPIRE_QUOTATION"
        ? await expireQuotation(resourceId, user, idempotencyKey, context.correlationId, organisationId)
        : await sendQuotation(resourceId, user, idempotencyKey, context.correlationId, organisationId);
    } else if (command === "SUBMIT_EXPENSE" || command === "APPROVE_EXPENSE") {
      if (!resourceId) throw new BusinessResourceError("Expense id is required.", 400);
      resource = command === "SUBMIT_EXPENSE"
        ? await submitExpense(resourceId, user, idempotencyKey, context.correlationId, organisationId)
        : await approveExpense(resourceId, user, idempotencyKey, context.correlationId, organisationId);
    } else if (command === "VERIFY_SUPPLIER") {
      if (!resourceId) throw new BusinessResourceError("Business party id is required.", 400);
      resource = await verifySupplier(resourceId, user, idempotencyKey, context.correlationId, organisationId);
    } else {
      const payload = await readBoundedJson<never>(request, 262_144);
      if (command === "CREATE_BUSINESS_PARTY") resource = await createBusinessParty(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "UPDATE_BUSINESS_PARTY") {
        if (!resourceId) throw new BusinessResourceError("Business party id is required.", 400);
        resource = await updateBusinessParty(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "DEACTIVATE_BUSINESS_PARTY") {
        if (!resourceId) throw new BusinessResourceError("Business party id is required.", 400);
        resource = await deactivateBusinessParty(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "SYNTHETIC_VERIFY_BUSINESS_PARTY") {
        if (!resourceId) throw new BusinessResourceError("Business party id is required.", 400);
        resource = await syntheticallyVerifyBusinessParty(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "CREATE_QUOTATION") resource = await createQuotation(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "UPDATE_QUOTATION") {
        if (!resourceId) throw new BusinessResourceError("Quotation id is required.", 400);
        resource = await updateQuotation(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "REJECT_QUOTATION") {
        if (!resourceId) throw new BusinessResourceError("Quotation id is required.", 400);
        resource = await rejectQuotation(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "CONVERT_QUOTATION") {
        if (!resourceId) throw new BusinessResourceError("Quotation id is required.", 400);
        await requireLicensedPermission(user, "invoices:submit", { requestedOrganisationId: organisationId });
        resource = await convertQuotationToInvoice(resourceId, payload, user, idempotencyKey, context, organisationId) as unknown as Record<string, unknown>;
      }
      else if (command === "POST_JOURNAL") resource = await postJournal(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_EXPENSE") resource = await createExpense(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "LINK_EXPENSE_RECEIPT") {
        if (!resourceId) throw new BusinessResourceError("Expense id is required.", 400);
        resource = await linkExpenseReceipt(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "DECIDE_EXPENSE") {
        if (!resourceId) throw new BusinessResourceError("Expense id is required.", 400);
        resource = await decideExpense(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "RECORD_STOCK_MOVEMENT") resource = await recordStockMovement(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_PRODUCT") resource = await createProduct(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_WAREHOUSE") resource = await createWarehouse(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "TRANSFER_STOCK") resource = await transferStock(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_PROJECT") resource = await createProject(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_ACCOUNT") resource = await createAccount(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "REVERSE_JOURNAL_ENTRY") {
        if (!resourceId) throw new BusinessResourceError("Journal entry id is required.", 400);
        resource = await reverseJournalEntry(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "CLOSE_ACCOUNTING_PERIOD") resource = await closeAccountingPeriod(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "CREATE_EXPENSE_CATEGORY") resource = await createExpenseCategory(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      else if (command === "REJECT_EXPENSE") {
        if (!resourceId) throw new BusinessResourceError("Expense id is required.", 400);
        resource = await rejectExpense(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else if (command === "APPROVE_PROJECT_BUDGET") {
        if (!resourceId) throw new BusinessResourceError("Project id is required.", 400);
        resource = await approveProjectBudget(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
      else {
        if (!resourceId) throw new BusinessResourceError("Project id is required.", 400);
        resource = await postProjectCost(resourceId, payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
      }
    }
    if (!resource) throw new RepositoryConflictError("The idempotent resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = ["ACCEPT_QUOTATION", "UPDATE_BUSINESS_PARTY", "DEACTIVATE_BUSINESS_PARTY", "UPDATE_QUOTATION", "SEND_QUOTATION", "REJECT_QUOTATION", "EXPIRE_QUOTATION", "CLOSE_ACCOUNTING_PERIOD", "SUBMIT_EXPENSE", "APPROVE_EXPENSE", "REJECT_EXPENSE", "APPROVE_PROJECT_BUDGET", "VERIFY_SUPPLIER"].includes(command) ? 200 : 201;
    return Response.json({ resource }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      // Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): this branch returned without ever recording RATE_LIMIT_ABUSE's input event, even though actorId was already known here.
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof BusinessValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof CounterpartyTrustValidationError) return problem(422, "COUNTERPARTY_TRUST_VALIDATION_FAILED", "Validation failed", error.message, context.correlationId);
    if (error instanceof InvoiceValidationError) return problem(422, "INVOICE_VALIDATION_FAILED", "Invoice validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof BusinessResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "BUSINESS_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The business command could not be completed.", context.correlationId);
  }
}
