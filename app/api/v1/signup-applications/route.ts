import { getChatGPTUser } from "@/app/chatgpt-auth";
import { submitSelfServeSignup, type SelfServeIdentityClaim } from "@/lib/data/signup-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { normalizeAndValidateSelfServeSignup, SignupValidationError } from "@/lib/domain/signup";
import {
  emitStructuredSecurityLog,
  enforceSelfServeSignupEmailRateLimit,
  enforceSelfServeSignupSourceRateLimits,
  readBoundedJson,
  recordSecurityEvent,
  requestContext,
  RequestGuardError,
} from "@/lib/security/request";

const MAX_SIGNUP_BYTES = 32_768;

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title,
    status,
    code,
    detail,
    correlationId,
    ...(errors ? { errors } : {}),
  }, {
    status,
    headers: {
      "content-type": "application/problem+json",
      "x-correlation-id": correlationId,
      "cache-control": "no-store",
      ...(retryAfter ? { "retry-after": String(retryAfter) } : {}),
    },
  });
}

export async function POST(request: Request) {
  const started = Date.now();
  const context = await requestContext(request);
  let outcome = "ERROR";
  try {
    await enforceSelfServeSignupSourceRateLimits(context);
    const payload = normalizeAndValidateSelfServeSignup(await readBoundedJson<unknown>(request, MAX_SIGNUP_BYTES));
    await enforceSelfServeSignupEmailRateLimit(payload.contact_email);
    const workspaceUser = await getChatGPTUser();
    const identity: SelfServeIdentityClaim | null = workspaceUser ? {
      provider: "SITES_WORKSPACE",
      subject: workspaceUser.userId,
      email: workspaceUser.email,
    } : null;
    const accepted = await submitSelfServeSignup(
      payload,
      request.headers.get("idempotency-key")?.trim() ?? "",
      context.correlationId,
      identity,
    );
    outcome = "ACCEPTED_PENDING_VERIFICATION";
    await recordSecurityEvent({
      eventType: "SELF_SERVE_SIGNUP_ACCEPTED",
      severity: "LOW",
      context,
      action: "SELF_SERVE_SIGNUP_SUBMIT",
      outcome,
      details: {
        identity_asserted: Boolean(identity),
        requested_plan_code: payload.plan_code,
        activation_effect: false,
      },
    });
    return Response.json(accepted, {
      status: 202,
      headers: {
        "x-correlation-id": context.correlationId,
        "cache-control": "no-store",
        "content-type": "application/json",
      },
    });
  } catch (cause) {
    if (cause instanceof SignupValidationError) {
      const companyAdminRequired = cause.messages.some((message) => message.code === "COMPANY_ADMIN_AUTHORITY_REQUIRED");
      outcome = companyAdminRequired ? "COMPANY_ADMIN_AUTHORITY_REQUIRED" : "VALIDATION_REJECTED";
      await recordSecurityEvent({
        eventType: "SELF_SERVE_SIGNUP_REJECTED",
        severity: "LOW",
        context,
        action: "SELF_SERVE_SIGNUP_SUBMIT",
        outcome,
        details: { reason_code: cause.messages[0]?.code ?? "VALIDATION_FAILED" },
      });
      return companyAdminRequired
        ? problem(403, "COMPANY_ADMIN_AUTHORITY_REQUIRED", "Company administrator required", "Only the verified Company System Administrator may start a commercial subscription application.", context.correlationId, cause.messages)
        : problem(422, "VALIDATION_FAILED", "Validation failed", "The signup application contains invalid or missing values.", context.correlationId, cause.messages);
    }
    if (cause instanceof RepositoryConflictError) {
      outcome = "DUPLICATE_REJECTED";
      await recordSecurityEvent({
        eventType: "SELF_SERVE_SIGNUP_DUPLICATE",
        severity: "LOW",
        context,
        action: "SELF_SERVE_SIGNUP_SUBMIT",
        outcome,
        details: { reason_code: "PENDING_OR_EXISTING_IDENTITY" },
      });
      return problem(409, "SIGNUP_CONFLICT", "Signup conflict", "A pending or existing application already covers the supplied identity.", context.correlationId);
    }
    if (cause instanceof RequestGuardError) {
      outcome = cause.code;
      await recordSecurityEvent({
        eventType: "SELF_SERVE_SIGNUP_REQUEST_BLOCKED",
        severity: cause.status === 429 ? "MEDIUM" : "LOW",
        context,
        action: "SELF_SERVE_SIGNUP_SUBMIT",
        outcome,
        details: { reason_code: cause.code },
      });
      return problem(cause.status, cause.code, "Request rejected", cause.message, context.correlationId, undefined, cause.retryAfter);
    }
    await recordSecurityEvent({
      eventType: "SELF_SERVE_SIGNUP_ERROR",
      severity: "HIGH",
      context,
      action: "SELF_SERVE_SIGNUP_SUBMIT",
      outcome,
      details: { reason_code: "INTERNAL_ERROR" },
    });
    return problem(500, "INTERNAL_ERROR", "Internal error", "The signup application could not be accepted.", context.correlationId);
  } finally {
    emitStructuredSecurityLog({
      level: outcome === "ERROR" ? "ERROR" : "INFO",
      event: "self_serve_signup_submission",
      correlationId: context.correlationId,
      outcome,
      durationMs: Date.now() - started,
    });
  }
}
