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

export const PROTECTED_PERMISSION_PREFIXES = ["namra:", "platform:", "security-policy:", "tax-rules:", "licensing-state:", "tenant-override:"] as const;

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
    if (PROTECTED_PERMISSION_PREFIXES.some((prefix) => permission.startsWith(prefix))) {
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

const WORKFLOW_DOMAIN_ACTIONS = new Set(["PURCHASE_REQUEST", "EXPENSE", "JOURNAL", "VAT_RETURN", "ROLE_CHANGE", "PRIMARY_ADMIN_CHANGE", "API_CREDENTIAL"]);

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

export function hasRecentStepUp(input: { assurance: string | null; reauthenticatedAt: string | null; now?: number; maxAgeMs?: number }): boolean {
  if (input.assurance !== "MFA_STEP_UP") return false;
  if (!input.reauthenticatedAt) return false;
  const occurred = Date.parse(input.reauthenticatedAt);
  if (!Number.isFinite(occurred)) return false;
  const age = (input.now ?? Date.now()) - occurred;
  return age >= 0 && age <= (input.maxAgeMs ?? 5 * 60_000);
}

export function quarterlyAccessReviewWindow(date = new Date()): { key: string; periodStart: string; dueAt: string } {
  const year = date.getUTCFullYear();
  const quarter = Math.floor(date.getUTCMonth() / 3) + 1;
  const startMonth = (quarter - 1) * 3;
  const periodStart = new Date(Date.UTC(year, startMonth, 1, 0, 0, 0)).toISOString().slice(0, 10);
  const dueAt = new Date(Date.UTC(year, startMonth + 3, 0, 23, 59, 59)).toISOString();
  return { key: `${year}-Q${quarter}`, periodStart, dueAt };
}
