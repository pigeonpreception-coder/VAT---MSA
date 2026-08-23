import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import {
  acceptQuotation,
  BusinessResourceError,
  convertQuotationToInvoice,
  createBusinessParty,
  createExpense,
  decideExpense,
  createProject,
  createQuotation,
  getBusinessPlatformSnapshot,
  expireQuotation,
  linkExpenseReceipt,
  postJournal,
  recordStockMovement,
  rejectQuotation,
  deactivateBusinessParty,
  updateBusinessParty,
  updateQuotation,
} from "@/lib/data/business-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { BusinessValidationError } from "@/lib/domain/business";
import { InvoiceValidationError } from "@/lib/domain/invoice";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type BusinessSection = "parties" | "quotations" | "journals" | "expenses" | "balances" | "projects";
export type BusinessCommand = "CREATE_BUSINESS_PARTY" | "UPDATE_BUSINESS_PARTY" | "DEACTIVATE_BUSINESS_PARTY" | "CREATE_QUOTATION" | "UPDATE_QUOTATION" | "ACCEPT_QUOTATION" | "REJECT_QUOTATION" | "EXPIRE_QUOTATION" | "CONVERT_QUOTATION" | "POST_JOURNAL" | "CREATE_EXPENSE" | "LINK_EXPENSE_RECEIPT" | "DECIDE_EXPENSE" | "RECORD_STOCK_MOVEMENT" | "CREATE_PROJECT";

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
    if (command === "ACCEPT_QUOTATION" || command === "EXPIRE_QUOTATION") {
      if (!resourceId) throw new BusinessResourceError("Quotation id is required.", 400);
      resource = command === "ACCEPT_QUOTATION"
        ? await acceptQuotation(resourceId, user, idempotencyKey, context.correlationId, organisationId)
        : await expireQuotation(resourceId, user, idempotencyKey, context.correlationId, organisationId);
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
      else resource = await createProject(payload, user, idempotencyKey, context.correlationId, organisationId) as Record<string, unknown> | null;
    }
    if (!resource) throw new RepositoryConflictError("The idempotent resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = ["ACCEPT_QUOTATION", "UPDATE_BUSINESS_PARTY", "DEACTIVATE_BUSINESS_PARTY", "UPDATE_QUOTATION", "REJECT_QUOTATION", "EXPIRE_QUOTATION", "LINK_EXPENSE_RECEIPT", "DECIDE_EXPENSE"].includes(command) ? 200 : 201;
    return Response.json({ resource }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    if (error instanceof BusinessValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
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
