import { TENANT_GRANTABLE_PERMISSIONS } from "@/lib/domain/access";

export type LicenseState =
  | "TRIAL"
  | "ACTIVE"
  | "GRACE_PERIOD"
  | "PENDING_RENEWAL"
  | "SUSPENDED"
  | "EXPIRED"
  | "CANCELLED";

export type OperationClass = "READ" | "EXPORT" | "BUSINESS_WRITE" | "COMPLIANCE_WRITE" | "CORRECTION_WRITE" | "ADMIN_WRITE";
export type CapacityMode = "FINITE" | "UNLIMITED" | "NOT_APPLICABLE";

export type EntitlementEvaluation = {
  allowed: boolean;
  code: string;
  reason: string;
  remaining: number | null;
  obligations: string[];
};

export type EntitlementInput = {
  licenseState: LicenseState;
  featureKey: string;
  featureEnabled: boolean;
  operationClass: OperationClass;
  capacityMode: CapacityMode;
  limit: number | null;
  used: number;
  reserved?: number;
  requested?: number;
};

const CONTINUITY_OPERATIONS = new Set<OperationClass>(["READ", "EXPORT", "COMPLIANCE_WRITE", "CORRECTION_WRITE"]);

export function evaluateEntitlement(input: EntitlementInput): EntitlementEvaluation {
  const requested = Math.max(0, input.requested ?? 1);
  const reserved = Math.max(0, input.reserved ?? 0);
  const used = Math.max(0, input.used);
  const configurationValid = (input.capacityMode === "FINITE" && input.limit !== null && input.limit > 0)
    || (input.capacityMode !== "FINITE" && input.limit === null);
  const featureCapacityValid = input.featureKey !== "USER_SEATS" || input.capacityMode !== "NOT_APPLICABLE";
  if (!configurationValid || !featureCapacityValid) {
    return {
      allowed: false,
      code: "ENTITLEMENT_CONFIGURATION_INVALID",
      reason: `${input.featureKey} has an invalid explicit capacity configuration.`,
      remaining: null,
      obligations: ["FAIL_CLOSED", "ALERT_CONFIGURATION_OWNER"],
    };
  }
  const remaining = input.capacityMode === "FINITE" && input.limit !== null
    ? Math.max(0, input.limit - used - reserved)
    : null;

  if (!input.featureEnabled) {
    return { allowed: false, code: "FEATURE_NOT_ENTITLED", reason: `${input.featureKey} is not included in the organisation licence.`, remaining, obligations: [] };
  }

  if (["SUSPENDED", "EXPIRED", "CANCELLED"].includes(input.licenseState)) {
    if (!CONTINUITY_OPERATIONS.has(input.operationClass)) {
      return {
        allowed: false,
        code: `LICENSE_${input.licenseState}`,
        reason: "The licence is restricted. Historical records remain preserved and authorised continuity actions remain available.",
        remaining,
        obligations: ["PRESERVE_RECORDS", "DISPLAY_RENEWAL_CONTACT"],
      };
    }
    return {
      allowed: true,
      code: "CONTINUITY_ACCESS",
      reason: "Authorised read, export, compliance or correction access remains available without deleting records.",
      remaining,
      obligations: ["READ_ONLY_UNLESS_CONTINUITY_ACTION", "ENHANCED_AUDIT"],
    };
  }

  if (input.licenseState === "GRACE_PERIOD" && input.operationClass === "ADMIN_WRITE") {
    return {
      allowed: false,
      code: "GRACE_PERIOD_NO_EXPANSION",
      reason: "The grace period does not permit expanding users, branches, roles or licensed capacity.",
      remaining,
      obligations: ["DISPLAY_RENEWAL_CONTACT"],
    };
  }

  if (remaining !== null && requested > remaining) {
    return {
      allowed: false,
      code: "ENTITLEMENT_LIMIT_EXCEEDED",
      reason: `The requested operation exceeds the ${input.featureKey} licence limit.`,
      remaining,
      obligations: ["NO_PARTIAL_WRITE", "AUDIT_DENIAL"],
    };
  }

  return {
    allowed: true,
    code: "ENTITLED",
    reason: "The organisation licence permits this operation.",
    remaining: remaining === null ? null : remaining - requested,
    obligations: input.licenseState === "TRIAL" ? ["TRIAL_LABEL"] : [],
  };
}

export type OrganisationRoleInput = {
  name: string;
  description?: string;
  permissions: string[];
  branchScope?: string[];
  approvalLimitCents?: number | null;
};

export class ControlPlaneValidationError extends Error {
  readonly code: string;

  constructor(code: string, message: string) {
    super(message);
    this.name = "ControlPlaneValidationError";
    this.code = code;
  }
}

function cleanLabel(value: unknown, field: string, max = 100): string {
  if (typeof value !== "string") throw new ControlPlaneValidationError("FIELD_REQUIRED", `${field} is required.`);
  const result = value.trim().replace(/\s+/g, " ");
  if (result.length < 2 || result.length > max) throw new ControlPlaneValidationError("FIELD_INVALID", `${field} must contain 2 to ${max} characters.`);
  if (/[<>]/.test(result) || [...result].some((character) => character.charCodeAt(0) < 32)) throw new ControlPlaneValidationError("FIELD_INVALID", `${field} contains unsupported characters.`);
  return result;
}

export function normalizeOrganisationRole(input: unknown): OrganisationRoleInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A role definition is required.");
  const source = input as Record<string, unknown>;
  const permissions = Array.isArray(source.permissions)
    ? [...new Set(source.permissions.filter((item): item is string => typeof item === "string").map((item) => item.trim().toLowerCase()))]
    : [];
  if (!permissions.length) throw new ControlPlaneValidationError("PERMISSION_REQUIRED", "Select at least one permission.");
  for (const permission of permissions) {
    if (!/^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*$/.test(permission)) throw new ControlPlaneValidationError("PERMISSION_INVALID", `Permission ${permission} is not valid.`);
    if (!TENANT_GRANTABLE_PERMISSIONS.has(permission)) {
      throw new ControlPlaneValidationError("PROTECTED_PERMISSION", `${permission} is system-controlled and cannot be placed in an organisation role.`);
    }
  }
  const approvalLimitCents = source.approval_limit_cents === null || source.approval_limit_cents === undefined
    ? null
    : Number(source.approval_limit_cents);
  if (approvalLimitCents !== null && (!Number.isSafeInteger(approvalLimitCents) || approvalLimitCents < 0)) {
    throw new ControlPlaneValidationError("APPROVAL_LIMIT_INVALID", "Approval limits must be non-negative integer minor units.");
  }
  return {
    name: cleanLabel(source.name, "Role name", 80),
    description: typeof source.description === "string" ? cleanLabel(source.description, "Description", 240) : undefined,
    permissions,
    branchScope: Array.isArray(source.branch_scope) ? source.branch_scope.filter((item): item is string => typeof item === "string") : [],
    approvalLimitCents,
  };
}

export type EmployeeInput = {
  employeeNumber: string;
  fullName: string;
  email: string;
  departmentId: string | null;
  branchId: string | null;
  jobTitleId: string | null;
  managerEmployeeId: string | null;
};

export function normalizeEmployee(input: unknown): EmployeeInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "An employee record is required.");
  const source = input as Record<string, unknown>;
  const email = String(source.email ?? "").trim().toLowerCase();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new ControlPlaneValidationError("EMAIL_INVALID", "A valid employee email is required.");
  const optionalId = (value: unknown) => typeof value === "string" && value.trim() ? value.trim() : null;
  return {
    employeeNumber: cleanLabel(source.employee_number, "Employee number", 40).toUpperCase(),
    fullName: cleanLabel(source.full_name, "Employee name", 120),
    email,
    departmentId: optionalId(source.department_id),
    branchId: optionalId(source.branch_id),
    jobTitleId: optionalId(source.job_title_id),
    managerEmployeeId: optionalId(source.manager_employee_id),
  };
}

export type WorkflowNodeInput = { id: string; type: "START" | "APPROVAL" | "END"; assigneeType?: "ROLE" | "USER" | "MANAGER"; assigneeRef?: string; label: string };
export type WorkflowTransitionInput = { from: string; to: string; condition?: { field: "amount_cents" | "branch_id" | "department_id"; operator: "LTE" | "GT" | "EQ"; value: string | number } };
export type WorkflowDefinitionInput = { name: string; domainAction: string; nodes: WorkflowNodeInput[]; transitions: WorkflowTransitionInput[] };

/**
 * Module 8 Phase C: REFUND added so a future Module 9 phase can register a
 * refund-approval workflow against this engine — Refund's own existing
 * maker-checker (lib/data/compliance-repository.ts's reviewRefund) is
 * deliberately NOT migrated onto it this phase. That is cross-module
 * surgery on already-shipped, tested code, out of scope for a phase that
 * stays inside Module 8's own files.
 */
const WORKFLOW_DOMAIN_ACTIONS = new Set(["PURCHASE_REQUEST", "EXPENSE", "JOURNAL", "VAT_RETURN", "ROLE_CHANGE", "PRIMARY_ADMIN_CHANGE", "API_CREDENTIAL", "REFUND"]);
const WORKFLOW_CONTEXT_FIELDS = new Set(["amount_cents", "branch_id", "department_id"]);
const ISO_TIMESTAMP_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/;

function normalizeWorkflowContext(contextRaw: unknown): Record<string, number | string> {
  if (contextRaw === undefined || contextRaw === null) return {};
  if (typeof contextRaw !== "object" || Array.isArray(contextRaw)) throw new ControlPlaneValidationError("CONTEXT_INVALID", "context must be an object.");
  const context: Record<string, number | string> = {};
  for (const [key, value] of Object.entries(contextRaw as Record<string, unknown>)) {
    if (!WORKFLOW_CONTEXT_FIELDS.has(key)) throw new ControlPlaneValidationError("CONTEXT_FIELD_UNSUPPORTED", `context.${key} is not a supported routing field.`);
    if (typeof value !== "number" && typeof value !== "string") throw new ControlPlaneValidationError("CONTEXT_VALUE_INVALID", `context.${key} must be a number or string.`);
    context[key] = value;
  }
  return context;
}

export function normalizeWorkflowDefinition(input: unknown): WorkflowDefinitionInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A workflow definition is required.");
  const source = input as Record<string, unknown>;
  const domainAction = String(source.domain_action ?? "").trim().toUpperCase();
  if (!WORKFLOW_DOMAIN_ACTIONS.has(domainAction)) throw new ControlPlaneValidationError("WORKFLOW_DOMAIN_UNSUPPORTED", "The workflow domain action is not configurable.");
  if (!Array.isArray(source.nodes) || source.nodes.length < 2 || source.nodes.length > 30) throw new ControlPlaneValidationError("WORKFLOW_NODES_INVALID", "A workflow needs 2 to 30 typed nodes.");
  const nodes = source.nodes.map((value, index) => {
    if (!value || typeof value !== "object" || Array.isArray(value)) throw new ControlPlaneValidationError("WORKFLOW_NODE_INVALID", `Node ${index + 1} is invalid.`);
    const node = value as Record<string, unknown>;
    const id = String(node.id ?? "").trim();
    const type = String(node.type ?? "").trim().toUpperCase() as WorkflowNodeInput["type"];
    if (!/^[a-z][a-z0-9-]{0,39}$/i.test(id) || !["START", "APPROVAL", "END"].includes(type)) throw new ControlPlaneValidationError("WORKFLOW_NODE_INVALID", `Node ${index + 1} is invalid.`);
    const assigneeType = node.assignee_type ? String(node.assignee_type).toUpperCase() as WorkflowNodeInput["assigneeType"] : undefined;
    const assigneeRef = typeof node.assignee_ref === "string" ? node.assignee_ref.trim() : undefined;
    if (type === "APPROVAL" && (!assigneeType || !["ROLE", "USER", "MANAGER"].includes(assigneeType))) throw new ControlPlaneValidationError("WORKFLOW_ASSIGNEE_REQUIRED", `Approval node ${id} needs a typed assignee.`);
    return { id, type, assigneeType, assigneeRef, label: cleanLabel(node.label ?? id, `Node ${id} label`, 80) };
  });
  const nodeIds = new Set(nodes.map((node) => node.id));
  if (nodeIds.size !== nodes.length) throw new ControlPlaneValidationError("WORKFLOW_NODE_DUPLICATE", "Workflow node IDs must be unique.");
  if (nodes.filter((node) => node.type === "START").length !== 1 || nodes.filter((node) => node.type === "END").length !== 1) {
    throw new ControlPlaneValidationError("WORKFLOW_TERMINALS_INVALID", "A workflow requires exactly one START and one END node.");
  }
  if (!Array.isArray(source.transitions) || !source.transitions.length) throw new ControlPlaneValidationError("WORKFLOW_TRANSITIONS_REQUIRED", "Workflow transitions are required.");
  const transitions = source.transitions.map((value) => {
    if (!value || typeof value !== "object" || Array.isArray(value)) throw new ControlPlaneValidationError("WORKFLOW_TRANSITION_INVALID", "A workflow transition is invalid.");
    const transition = value as Record<string, unknown>;
    const from = String(transition.from ?? "").trim();
    const to = String(transition.to ?? "").trim();
    if (!nodeIds.has(from) || !nodeIds.has(to) || from === to) throw new ControlPlaneValidationError("WORKFLOW_TRANSITION_INVALID", `${from} to ${to} is not a valid transition.`);
    let condition: WorkflowTransitionInput["condition"];
    if (transition.condition !== undefined) {
      if (!transition.condition || typeof transition.condition !== "object" || Array.isArray(transition.condition)) throw new ControlPlaneValidationError("WORKFLOW_CONDITION_INVALID", "Workflow conditions must use the typed condition vocabulary.");
      const raw = transition.condition as Record<string, unknown>;
      const field = String(raw.field ?? "") as NonNullable<WorkflowTransitionInput["condition"]>["field"];
      const operator = String(raw.operator ?? "").toUpperCase() as NonNullable<WorkflowTransitionInput["condition"]>["operator"];
      if (!["amount_cents", "branch_id", "department_id"].includes(field) || !["LTE", "GT", "EQ"].includes(operator)) throw new ControlPlaneValidationError("WORKFLOW_CONDITION_INVALID", "Workflow conditions must use approved fields and operators.");
      condition = { field, operator, value: typeof raw.value === "number" ? raw.value : String(raw.value ?? "") };
    }
    return { from, to, condition };
  });
  return { name: cleanLabel(source.name, "Workflow name", 100), domainAction, nodes, transitions };
}

export function assertWorkflowDecision(input: { actorId: string; initiatedBy: string; assignedUserId?: string | null; decision: string; emergencyOverride?: boolean }): void {
  if (input.emergencyOverride) throw new ControlPlaneValidationError("EMERGENCY_OVERRIDE_DISABLED", "Emergency segregation-of-duties override is disabled.");
  if (input.actorId === input.initiatedBy) throw new ControlPlaneValidationError("SELF_APPROVAL_DENIED", "The initiator cannot approve or reject their own protected transaction.");
  if (input.assignedUserId && input.assignedUserId !== input.actorId) throw new ControlPlaneValidationError("TASK_NOT_ASSIGNED", "The workflow task is assigned to another user.");
  if (!["APPROVE", "REJECT"].includes(input.decision.toUpperCase())) throw new ControlPlaneValidationError("DECISION_INVALID", "The workflow decision must be APPROVE or REJECT.");
}

export type WorkflowAssignmentInput = { domainAction: string; resourceType: string; resourceId: string; context: Record<string, number | string> };

/**
 * Module 8 Phase C Assign: the previously entirely-missing command — a
 * 2026-08-26 audit found zero `INSERT INTO workflow_instances`/
 * `workflow_assignments` anywhere in this codebase outside their own
 * `CREATE TABLE` statements, meaning the whole Create/Publish/Decide
 * pipeline already built here was unreachable end-to-end on a fresh
 * database. `context` is deliberately restricted to the same typed field
 * vocabulary `normalizeWorkflowDefinition`'s transition conditions already
 * use (`amount_cents`/`branch_id`/`department_id`) — the routing context a
 * workflow's own conditions can reference, not an arbitrary payload.
 */
export function normalizeWorkflowAssignment(input: unknown): WorkflowAssignmentInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A workflow assignment object is required.");
  const source = input as Record<string, unknown>;
  const domainAction = String(source.domain_action ?? "").trim().toUpperCase();
  if (!WORKFLOW_DOMAIN_ACTIONS.has(domainAction)) throw new ControlPlaneValidationError("WORKFLOW_DOMAIN_UNSUPPORTED", "The workflow domain action is not configurable.");
  const resourceType = String(source.resource_type ?? "").trim().toUpperCase();
  if (!/^[A-Z][A-Z0-9_]{1,39}$/.test(resourceType)) throw new ControlPlaneValidationError("RESOURCE_TYPE_INVALID", "resource_type must contain 2 to 40 uppercase letters, numbers or underscores.");
  const resourceId = String(source.resource_id ?? "").trim();
  if (!resourceId || resourceId.length > 100) throw new ControlPlaneValidationError("RESOURCE_ID_INVALID", "resource_id is required.");
  return { domainAction, resourceType, resourceId, context: normalizeWorkflowContext(source.context) };
}

/** Module 8 Phase C Test: a dry-run path resolution, no side effects — validates a workflow's routing before publish. */
export function normalizeWorkflowTestContext(input: unknown): Record<string, number | string> {
  if (input === undefined || input === null) return {};
  if (typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A test request object is required.");
  return normalizeWorkflowContext((input as Record<string, unknown>).context);
}

/** Module 8 Phase C: shared by Assign/Decide's path resolution and Test's dry run — the one place a transition condition is actually evaluated. */
export function evaluateWorkflowCondition(condition: { field: string; operator: string; comparison_value: string }, context: Record<string, unknown>): boolean {
  const raw = context[condition.field];
  const numeric = typeof raw === "number" ? raw : Number(raw);
  const comparison = Number(condition.comparison_value);
  if (Number.isFinite(numeric) && Number.isFinite(comparison)) {
    if (condition.operator === "LTE") return numeric <= comparison;
    if (condition.operator === "GT") return numeric > comparison;
    if (condition.operator === "EQ") return numeric === comparison;
  }
  if (condition.operator === "EQ") return String(raw ?? "") === condition.comparison_value;
  return false;
}

export type DelegationInput = { delegatorUserId: string; delegateUserId: string; workflowId: string | null; scope: "ALL" | "WORKFLOW"; effectiveFrom: string; effectiveTo: string; reason: string };

/** Module 8 Phase C Delegate: workflow_delegations previously had a real table and zero read or write code anywhere. */
export function normalizeDelegation(input: unknown): DelegationInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A delegation object is required.");
  const source = input as Record<string, unknown>;
  const delegatorUserId = String(source.delegator_user_id ?? "").trim();
  if (!delegatorUserId) throw new ControlPlaneValidationError("DELEGATOR_REQUIRED", "delegator_user_id is required.");
  const delegateUserId = String(source.delegate_user_id ?? "").trim();
  if (!delegateUserId) throw new ControlPlaneValidationError("DELEGATE_REQUIRED", "delegate_user_id is required.");
  if (delegatorUserId === delegateUserId) throw new ControlPlaneValidationError("DELEGATION_SELF", "A user cannot delegate to themselves.");
  const workflowId = typeof source.workflow_id === "string" && source.workflow_id.trim() ? source.workflow_id.trim() : null;
  const scope: DelegationInput["scope"] = workflowId ? "WORKFLOW" : "ALL";
  const effectiveFrom = String(source.effective_from ?? "").trim();
  const effectiveTo = String(source.effective_to ?? "").trim();
  if (!ISO_TIMESTAMP_PATTERN.test(effectiveFrom) || !ISO_TIMESTAMP_PATTERN.test(effectiveTo)) throw new ControlPlaneValidationError("EFFECTIVE_RANGE_INVALID", "effective_from/effective_to must be ISO UTC timestamps.");
  if (Date.parse(effectiveTo) <= Date.parse(effectiveFrom)) throw new ControlPlaneValidationError("EFFECTIVE_RANGE_INVALID", "effective_to must be after effective_from.");
  const reason = String(source.reason ?? "").trim().replace(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character delegation reason.");
  return { delegatorUserId, delegateUserId, workflowId, scope, effectiveFrom, effectiveTo, reason };
}

export function hasRecentStepUp(input: { assurance: string | null; reauthenticatedAt: string | null; now?: number; maxAgeMs?: number }): boolean {
  if (input.assurance !== "MFA_STEP_UP") return false;
  if (!input.reauthenticatedAt) return false;
  const occurred = Date.parse(input.reauthenticatedAt);
  if (!Number.isFinite(occurred)) return false;
  const age = (input.now ?? Date.now()) - occurred;
  return age >= 0 && age <= (input.maxAgeMs ?? 5 * 60_000);
}

export type NavigationParentType = "workspace" | "folder";
export type NavigationChildrenQuery = { parentType: NavigationParentType; parentId: string };

/** Workspace & Navigation GetChildren: a scoped drill-down (one workspace's
 * top-level folders, or one folder's sub-folders + items) rather than
 * fetching the whole tree — navigation_folders nests via parent_folder_id,
 * which GetWorkspace's existing flat query doesn't traverse. */
export function normalizeNavigationChildrenQuery(parentType: unknown, parentId: unknown): NavigationChildrenQuery {
  const type = String(parentType ?? "").trim().toLowerCase();
  if (type !== "workspace" && type !== "folder") {
    throw new ControlPlaneValidationError("PARENT_TYPE_INVALID", "parent_type must be workspace or folder.");
  }
  const id = String(parentId ?? "").trim();
  if (!id) throw new ControlPlaneValidationError("PARENT_ID_REQUIRED", "parent_id is required.");
  return { parentType: type as NavigationParentType, parentId: id };
}

export type NavigationPreferenceInput = { preferenceType: string; value: string };

/** Workspace & Navigation SavePreference. Stores value as a JSON string,
 * matching how other JSON-blob columns in this schema are stored. */
export function normalizeNavigationPreference(input: unknown): NavigationPreferenceInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A navigation preference object is required.");
  }
  const source = input as Record<string, unknown>;
  const preferenceType = String(source.preference_type ?? "").trim().toLowerCase();
  if (!/^[a-z][a-z0-9_]{1,59}$/.test(preferenceType)) {
    throw new ControlPlaneValidationError("PREFERENCE_TYPE_INVALID", "preference_type must contain 2 to 60 lowercase letters, numbers or underscores, starting with a letter.");
  }
  if (source.value === undefined) throw new ControlPlaneValidationError("VALUE_REQUIRED", "value is required.");
  let serialized: string;
  try {
    serialized = JSON.stringify(source.value);
  } catch {
    throw new ControlPlaneValidationError("VALUE_NOT_SERIALIZABLE", "value must be JSON-serializable.");
  }
  if (!serialized || serialized.length > 8_192) {
    throw new ControlPlaneValidationError("VALUE_TOO_LARGE", "value must serialize to at most 8192 characters.");
  }
  return { preferenceType, value: serialized };
}

export type CapabilityGrantInput = { userId: string; capability: "BUYER" | "SELLER" };

/**
 * Organisation Authorization GrantCapability. Distinct from Organisation's
 * EnableCapability (which turns BUYER/SELLER on for the organisation as a
 * whole): this grants an individual, already-active member of that
 * organisation visibility into the corresponding capability-scoped
 * navigation and portal — the repository layer additionally requires the
 * organisation itself to already hold the capability being granted.
 */
export function normalizeCapabilityGrant(input: unknown): CapabilityGrantInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A capability grant object is required.");
  }
  const source = input as Record<string, unknown>;
  const userId = String(source.user_id ?? "").trim();
  if (!userId) throw new ControlPlaneValidationError("USER_ID_REQUIRED", "user_id is required.");
  const capability = String(source.capability ?? "").trim().toUpperCase();
  if (capability !== "BUYER" && capability !== "SELLER") {
    throw new ControlPlaneValidationError("CAPABILITY_INVALID", "capability must be BUYER or SELLER.");
  }
  return { userId, capability: capability as "BUYER" | "SELLER" };
}

export type AdministratorAppointmentInput = { userId: string; administratorRoleCode: string; isPrimary: boolean; approvalReference: string };

/**
 * Organisation Administration AppointAdministrator. The administrator role
 * code itself is validated against the DB catalogue (organisation_
 * administrator_roles) in the repository layer, same pattern as
 * normalizeOrganisationRole's permission-catalogue check — this only
 * validates shape.
 */
export function normalizeAdministratorAppointment(input: unknown): AdministratorAppointmentInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "An administrator appointment object is required.");
  }
  const source = input as Record<string, unknown>;
  const userId = String(source.user_id ?? "").trim();
  if (!userId) throw new ControlPlaneValidationError("USER_ID_REQUIRED", "user_id is required.");
  const administratorRoleCode = String(source.administrator_role_code ?? "").trim().toUpperCase();
  if (!/^[A-Z][A-Z0-9_]{1,39}$/.test(administratorRoleCode)) {
    throw new ControlPlaneValidationError("ADMINISTRATOR_ROLE_INVALID", "administrator_role_code must contain 2 to 40 uppercase letters, numbers or underscores.");
  }
  const approvalReference = String(source.approval_reference ?? "").trim().replace(/\s+/g, " ");
  if (approvalReference.length < 5 || approvalReference.length > 240) {
    throw new ControlPlaneValidationError("APPROVAL_REFERENCE_REQUIRED", "Provide a 5 to 240 character approval_reference.");
  }
  return { userId, administratorRoleCode, isPrimary: source.is_primary === true, approvalReference };
}

export type EmployeeActivationInput = { userId: string };

/** Organisation Administration employee INVITED -> ACTIVE. Links an invited
 * employee record to an existing, already-active app_users row — does not
 * itself grant organisation access; that remains AssignMembership's job,
 * a deliberate separation between the HR record and the access grant. */
export function normalizeEmployeeActivation(input: unknown): EmployeeActivationInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "An employee activation object is required.");
  }
  const source = input as Record<string, unknown>;
  const userId = String(source.user_id ?? "").trim();
  if (!userId) throw new ControlPlaneValidationError("USER_ID_REQUIRED", "user_id is required.");
  return { userId };
}

export type AccessGrantType = "ROLE" | "CAPABILITY";
export type AccessRevocationInput = { grantType: AccessGrantType; grantId: string; reason: string };

/**
 * Access Governance RevokeAccess: revokes one specific already-granted role
 * (user_role_assignments, granted via decideAccessRequest's APPROVE branch)
 * or capability (user_capability_assignments, granted via GrantCapability)
 * on demand — see revokeAccessGrant in lib/data/control-plane-repository.ts
 * for why this didn't already exist as its own command.
 */
export function normalizeAccessRevocation(input: unknown): AccessRevocationInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "An access revocation object is required.");
  }
  const source = input as Record<string, unknown>;
  const grantType = String(source.grant_type ?? "").trim().toUpperCase();
  if (grantType !== "ROLE" && grantType !== "CAPABILITY") {
    throw new ControlPlaneValidationError("GRANT_TYPE_INVALID", "grant_type must be ROLE or CAPABILITY.");
  }
  const grantId = String(source.grant_id ?? "").trim();
  if (!grantId) throw new ControlPlaneValidationError("GRANT_ID_REQUIRED", "grant_id is required.");
  const reason = String(source.reason ?? "").trim().replace(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) {
    throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character revocation reason.");
  }
  return { grantType: grantType as AccessGrantType, grantId, reason };
}

export type OffboardingInput = { userId: string; reason: string };

/**
 * Access Governance Offboard: revokes every active role/capability grant and
 * the organisation membership itself for one user, immediately — the
 * access-only counterpart to terminateEmployee (which also ends the
 * employment record and a licence seat) and to certifyQuarterlyAccess's
 * REVOKE disposition (which is gated behind an open review reaching full
 * completion across every active member).
 */
export function normalizeOffboarding(input: unknown): OffboardingInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "An offboarding object is required.");
  }
  const source = input as Record<string, unknown>;
  const userId = String(source.user_id ?? "").trim();
  if (!userId) throw new ControlPlaneValidationError("USER_ID_REQUIRED", "user_id is required.");
  const reason = String(source.reason ?? "").trim().replace(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) {
    throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character offboarding reason.");
  }
  return { userId, reason };
}

export type LicenseStateAction = "ACTIVATE" | "SUSPEND" | "RENEW";
export type LicenseStateChangeInput = { action: LicenseStateAction; reason: string };

const LICENSE_STATE_ACTIONS: readonly LicenseStateAction[] = ["ACTIVATE", "SUSPEND", "RENEW"];

/** Licensing & Entitlements lifecycle commands (Activate/Suspend/Renew combined —
 * they're the same "move the licence to a new state" shape; Upgrade is a
 * distinct plan-change operation, see normalizeLicenseUpgrade below). */
export function normalizeLicenseStateChange(input: unknown): LicenseStateChangeInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A licence state-change object is required.");
  }
  const source = input as Record<string, unknown>;
  const action = String(source.action ?? "").trim().toUpperCase();
  if (!LICENSE_STATE_ACTIONS.includes(action as LicenseStateAction)) {
    throw new ControlPlaneValidationError("LICENSE_ACTION_INVALID", `action must be one of: ${LICENSE_STATE_ACTIONS.join(", ")}.`);
  }
  const reason = String(source.reason ?? "").trim().replace(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) {
    throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character reason.");
  }
  return { action: action as LicenseStateAction, reason };
}

const LICENSE_STATE_TRANSITIONS: Record<LicenseStateAction, readonly LicenseState[]> = {
  ACTIVATE: ["TRIAL", "GRACE_PERIOD", "PENDING_RENEWAL", "SUSPENDED"],
  SUSPEND: ["TRIAL", "ACTIVE", "GRACE_PERIOD", "PENDING_RENEWAL"],
  RENEW: ["ACTIVE", "GRACE_PERIOD", "PENDING_RENEWAL", "EXPIRED"],
};

/** EXPIRED/CANCELLED are deliberately terminal for these three actions — reaching
 * either from here requires a new subscription, not a state-change command. */
export function assertLicenseStateTransition(action: LicenseStateAction, currentState: LicenseState): void {
  if (!LICENSE_STATE_TRANSITIONS[action].includes(currentState)) {
    throw new ControlPlaneValidationError("LICENSE_TRANSITION_INVALID", `Cannot ${action.toLowerCase()} a licence currently in state ${currentState}.`);
  }
}

export type LicenseUpgradeInput = { licensePlanCode: string };

export function normalizeLicenseUpgrade(input: unknown): LicenseUpgradeInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new ControlPlaneValidationError("PAYLOAD_INVALID", "A licence upgrade object is required.");
  }
  const source = input as Record<string, unknown>;
  const licensePlanCode = String(source.license_plan_code ?? "").trim().toUpperCase();
  if (!/^[A-Z][A-Z0-9_-]{1,39}$/.test(licensePlanCode)) {
    throw new ControlPlaneValidationError("LICENSE_PLAN_CODE_INVALID", "license_plan_code must contain 2 to 40 letters, numbers, hyphens or underscores.");
  }
  return { licensePlanCode };
}

export function quarterlyAccessReviewWindow(date = new Date()): { key: string; periodStart: string; dueAt: string } {
  const year = date.getUTCFullYear();
  const quarter = Math.floor(date.getUTCMonth() / 3) + 1;
  const startMonth = (quarter - 1) * 3;
  const periodStart = new Date(Date.UTC(year, startMonth, 1, 0, 0, 0)).toISOString().slice(0, 10);
  const dueAt = new Date(Date.UTC(year, startMonth + 3, 0, 23, 59, 59)).toISOString();
  return { key: `${year}-Q${quarter}`, periodStart, dueAt };
}
