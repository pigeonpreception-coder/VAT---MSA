import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, hasPermission, isNationalScope } from "@/lib/auth";
import {
  ControlPlaneValidationError,
  evaluateEntitlement,
  assertWorkflowDecision,
  normalizeEmployee,
  normalizeOrganisationRole,
  normalizeWorkflowDefinition,
  quarterlyAccessReviewWindow,
  type LicenseState,
  type OperationClass,
} from "@/lib/domain/control-plane";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type OrganisationScope = { id: string; taxpayer_id: string; legal_name: string };
type LicenseRow = {
  id: string;
  organisation_id: string;
  plan_id: string;
  plan_code: string;
  plan_name: string;
  plan_version: number;
  state: LicenseState;
  retention_policy: string;
  current_period_start: string;
  current_period_end: string;
};
type EntitlementRow = {
  feature_key: string;
  name: string;
  description: string;
  metric_key: string | null;
  enabled: number;
  limit_value: number | null;
  used_value: number | null;
  reserved_value: number | null;
};

export type NavigationItem = { id: string; key: string; label: string; href: string; classification: string };
export type NavigationFolder = { id: string; key: string; label: string; items: NavigationItem[] };
export type NavigationWorkspace = { id: string; key: string; label: string; description: string; classification: string; folders: NavigationFolder[] };

async function resolveOrganisation(actor: UserContext, requestedOrganisationId?: string | null): Promise<OrganisationScope> {
  const db = await ensureDatabase();
  const organisationId = requestedOrganisationId ?? actor.organisationId;
  if (organisationId) {
    const row = await db.prepare("SELECT id,taxpayer_id,legal_name FROM organisations WHERE id=? AND status='ACTIVE'")
      .bind(organisationId).first<OrganisationScope>();
    if (!row) throw new AccessDeniedError("The organisation scope is unavailable.");
    if (!isNationalScope(actor) && row.taxpayer_id !== actor.taxpayerId) throw new AccessDeniedError("The requested organisation is outside your authorised scope.");
    return row;
  }
  if (!isNationalScope(actor)) throw new AccessDeniedError("An active organisation membership is required.");
  const row = await db.prepare(`SELECT o.id,o.taxpayer_id,o.legal_name FROM organisations o
    JOIN organisation_licenses l ON l.organisation_id=o.id
    WHERE o.status='ACTIVE' ORDER BY o.legal_name LIMIT 1`).first<OrganisationScope>();
  if (!row) throw new AccessDeniedError("No licensed organisation is available in this environment.");
  return row;
}

async function getLicense(db: D1Database, organisationId: string): Promise<LicenseRow> {
  const row = await db.prepare(`SELECT l.id,l.organisation_id,p.id AS plan_id,p.code AS plan_code,p.name AS plan_name,p.version AS plan_version,
    l.state,l.retention_policy,s.current_period_start,s.current_period_end
    FROM organisation_licenses l JOIN license_plans p ON p.id=l.license_plan_id JOIN subscriptions s ON s.id=l.subscription_id
    WHERE l.organisation_id=? ORDER BY l.effective_from DESC LIMIT 1`).bind(organisationId).first<LicenseRow>();
  if (!row) throw new AccessDeniedError("The organisation has no configured licence.");
  return row;
}

async function getEntitlements(db: D1Database, license: LicenseRow): Promise<EntitlementRow[]> {
  const result = await db.prepare(`SELECT e.feature_key,f.name,f.description,f.metric_key,e.enabled,e.limit_value,
    COALESCE(u.used_value,0) AS used_value,COALESCE(u.reserved_value,0) AS reserved_value
    FROM license_plan_entitlements e JOIN license_features f ON f.feature_key=e.feature_key
    LEFT JOIN license_usage u ON u.organisation_license_id=? AND u.metric_key=f.metric_key
      AND u.period_key IN ('2026-Q3','2026-08')
    WHERE e.license_plan_id=? ORDER BY f.name`).bind(license.id, license.plan_id).all<EntitlementRow>();
  return result.results;
}

export async function assertEntitledOperation(
  actor: UserContext,
  featureKey: string,
  operationClass: OperationClass,
  requested = 1,
  requestedOrganisationId?: string | null,
): Promise<{ organisation: OrganisationScope; license: LicenseRow }> {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const entitlement = (await getEntitlements(db, license)).find((item) => item.feature_key === featureKey);
  const evaluation = evaluateEntitlement({
    licenseState: license.state,
    featureKey,
    featureEnabled: entitlement?.enabled === 1,
    operationClass,
    limit: entitlement?.limit_value ?? null,
    used: entitlement?.used_value ?? 0,
    reserved: entitlement?.reserved_value ?? 0,
    requested,
  });
  if (!evaluation.allowed) throw new AccessDeniedError(`${evaluation.code}: ${evaluation.reason}`);
  if (operationClass === "ADMIN_WRITE") {
    const reviewWindow = quarterlyAccessReviewWindow();
    const currentReview = await db.prepare(`SELECT id,status,due_at FROM access_reviews
      WHERE organisation_id=? AND review_type='QUARTERLY' AND period_start=? AND status IN ('OPEN','COMPLETED') LIMIT 1`)
      .bind(organisation.id, reviewWindow.periodStart).first<{ id: string; status: string; due_at: string }>();
    if (!currentReview || (currentReview.status === "OPEN" && Date.parse(currentReview.due_at) < Date.now())) {
      throw new AccessDeniedError(`QUARTERLY_ACCESS_REVIEW_REQUIRED: Open or complete the ${reviewWindow.key} access review before privileged organisation changes.`);
    }
  }
  return { organisation, license };
}

export async function getEffectiveNavigation(actor: UserContext, requestedOrganisationId?: string | null): Promise<{ organisation: OrganisationScope; workspaces: NavigationWorkspace[] }> {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const [entitlements, capabilities, rows] = await Promise.all([
    getEntitlements(db, license),
    db.prepare("SELECT capability FROM organisation_capabilities WHERE organisation_id=? AND status='ACTIVE'").bind(organisation.id).all<{ capability: string }>(),
    db.prepare(`SELECT w.id AS workspace_id,w.workspace_key,w.label AS workspace_label,w.description,w.classification AS workspace_classification,
      f.id AS folder_id,f.folder_key,f.label AS folder_label,
      i.id AS item_id,i.item_key,i.label AS item_label,i.href,i.feature_key,i.capability,i.required_permission,i.classification AS item_classification
      FROM navigation_workspaces w JOIN navigation_folders f ON f.workspace_id=w.id AND f.status='ACTIVE'
      JOIN navigation_items i ON i.folder_id=f.id AND i.status='ACTIVE'
      WHERE w.status='ACTIVE' ORDER BY w.sort_order,f.sort_order,i.sort_order`).all<Record<string, string | null>>(),
  ]);
  const enabledFeatures = new Set(entitlements.filter((item) => item.enabled === 1).map((item) => item.feature_key));
  const capabilitySet = new Set([
    ...capabilities.results.map((item) => item.capability),
    ...actor.organisationId === organisation.id ? actor.capabilities : [],
  ]);
  const byWorkspace = new Map<string, NavigationWorkspace>();
  const folderIndex = new Map<string, NavigationFolder>();
  for (const row of rows.results) {
    if (!hasPermission(actor, String(row.required_permission))) continue;
    if (row.feature_key && !enabledFeatures.has(row.feature_key)) continue;
    if (row.capability && !capabilitySet.has(row.capability)) continue;
    const workspaceId = String(row.workspace_id);
    let workspace = byWorkspace.get(workspaceId);
    if (!workspace) {
      workspace = { id: workspaceId, key: String(row.workspace_key), label: String(row.workspace_label), description: String(row.description), classification: String(row.workspace_classification), folders: [] };
      byWorkspace.set(workspaceId, workspace);
    }
    const folderId = String(row.folder_id);
    let folder = folderIndex.get(folderId);
    if (!folder) {
      folder = { id: folderId, key: String(row.folder_key), label: String(row.folder_label), items: [] };
      folderIndex.set(folderId, folder);
      workspace.folders.push(folder);
    }
    folder.items.push({ id: String(row.item_id), key: String(row.item_key), label: String(row.item_label), href: String(row.href), classification: String(row.item_classification) });
  }
  return { organisation, workspaces: [...byWorkspace.values()] };
}

export async function getAdministrationSnapshot(actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const { organisation, license } = await assertEntitledOperation(actor, "ADMINISTRATION", "READ", 0, requestedOrganisationId);
  const [entitlements, employees, roles, workflows, tasks, accessRequests, accessReviews, structures, administrators, security] = await Promise.all([
    getEntitlements(db, license),
    db.prepare(`SELECT e.id,e.employee_number,e.full_name,e.email,e.status,e.last_activity_at,d.name AS department,j.name AS job_title,b.name AS branch
      FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN job_titles j ON j.id=e.job_title_id LEFT JOIN branches b ON b.id=e.branch_id
      WHERE e.organisation_id=? ORDER BY CASE e.status WHEN 'ACTIVE' THEN 1 WHEN 'INVITED' THEN 2 ELSE 3 END,e.full_name LIMIT 100`).bind(organisation.id).all<Record<string, string | null>>(),
    db.prepare(`SELECT r.id,r.name,r.description,r.version,r.approval_limit_cents,r.status,r.created_by,
      COALESCE(GROUP_CONCAT(rp.permission_code, ', '),'') AS permissions
      FROM organisation_roles r LEFT JOIN organisation_role_permissions rp ON rp.organisation_role_id=r.id
      WHERE r.organisation_id=? GROUP BY r.id ORDER BY r.name,r.version DESC`).bind(organisation.id).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT w.id,w.name,w.domain_action,w.status,v.version_number,v.status AS version_status,v.published_at
      FROM workflows w LEFT JOIN workflow_versions v ON v.workflow_id=w.id WHERE w.organisation_id=? ORDER BY w.name,v.version_number DESC`).bind(organisation.id).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT a.id,a.status,a.due_at,i.resource_type,i.resource_id,u.display_name AS assigned_to,initiator.display_name AS initiated_by
      FROM workflow_assignments a JOIN workflow_instances i ON i.id=a.workflow_instance_id
      LEFT JOIN app_users u ON u.id=a.assigned_user_id LEFT JOIN app_users initiator ON initiator.id=i.initiated_by
      WHERE i.organisation_id=? AND a.status='PENDING' ORDER BY a.due_at LIMIT 50`).bind(organisation.id).all<Record<string, string | null>>(),
    db.prepare(`SELECT r.id,r.status,r.justification,r.requested_at,subject.display_name AS subject,requester.display_name AS requested_by,role.name AS role_name
      FROM access_requests r JOIN app_users subject ON subject.id=r.subject_user_id JOIN app_users requester ON requester.id=r.requested_by
      JOIN organisation_roles role ON role.id=r.organisation_role_id WHERE r.organisation_id=? ORDER BY r.requested_at DESC LIMIT 50`).bind(organisation.id).all<Record<string, string | null>>(),
    db.prepare(`SELECT id,name,review_type,status,period_start,due_at,completed_at FROM access_reviews WHERE organisation_id=? ORDER BY due_at DESC LIMIT 20`).bind(organisation.id).all<Record<string, string | null>>(),
    db.prepare(`SELECT
      (SELECT COUNT(*) FROM departments WHERE organisation_id=? AND status='ACTIVE') AS departments,
      (SELECT COUNT(*) FROM business_units WHERE organisation_id=? AND status='ACTIVE') AS business_units,
      (SELECT COUNT(*) FROM branches WHERE organisation_id=? AND status='ACTIVE') AS branches,
      (SELECT COUNT(*) FROM job_titles WHERE organisation_id=? AND status='ACTIVE') AS job_titles`).bind(organisation.id, organisation.id, organisation.id, organisation.id).first<Record<string, number>>(),
    db.prepare(`SELECT a.id,a.administrator_role_code,a.scope,a.is_primary,a.status,u.display_name,u.email
      FROM organisation_administrators a JOIN app_users u ON u.id=a.user_id WHERE a.organisation_id=? ORDER BY a.is_primary DESC,u.display_name`).bind(organisation.id).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT
      (SELECT COUNT(*) FROM security_events WHERE occurred_at >= datetime('now','-30 days')) AS security_events_30d,
      (SELECT COUNT(*) FROM security_events WHERE event_type='AUTHENTICATION_FAILED' AND occurred_at >= datetime('now','-30 days')) AS failed_logins_30d,
      (SELECT COUNT(*) FROM sod_violations WHERE organisation_id=? AND status='OPEN') AS open_sod_violations`).bind(organisation.id).first<Record<string, number>>(),
  ]);
  return {
    organisation,
    license: { ...license, price: null, pricingConfigured: false },
    entitlements,
    employees: employees.results,
    roles: roles.results,
    workflows: workflows.results,
    tasks: tasks.results,
    accessRequests: accessRequests.results,
    accessReviews: accessReviews.results,
    structures: structures ?? {},
    administrators: administrators.results,
    security: security ?? {},
    integrations: { payments: "DISABLED", itas: "DISABLED_PENDING_AUTHORITY_CONTRACT", statutoryRules: "APPROVED_RULES_ONLY" },
  };
}

async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = stableStringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(id, actor.userId, actor.role, action, resourceType, resourceId, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
}

export async function inviteEmployee(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const employee = normalizeEmployee(input);
  const { organisation, license } = await assertEntitledOperation(actor, "USER_SEATS", "ADMIN_WRITE", 1, requestedOrganisationId);
  const db = await ensureDatabase();
  const duplicate = await db.prepare("SELECT id FROM employees WHERE organisation_id=? AND (employee_number=? OR lower(email)=lower(?)) LIMIT 1")
    .bind(organisation.id, employee.employeeNumber, employee.email).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError("An employee with this number or email already exists.");
  for (const [table, id] of [["departments", employee.departmentId], ["branches", employee.branchId], ["job_titles", employee.jobTitleId], ["employees", employee.managerEmployeeId]] as const) {
    if (!id) continue;
    const valid = await db.prepare(`SELECT id FROM ${table} WHERE id=? AND organisation_id=?`).bind(id, organisation.id).first<{ id: string }>();
    if (!valid) throw new ControlPlaneValidationError("REFERENCE_OUT_OF_SCOPE", `The selected ${table.replaceAll("_", " ")} record is outside this organisation.`);
  }
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO employees
      (id,organisation_id,user_id,employee_number,full_name,email,position_id,job_title_id,department_id,business_unit_id,branch_id,manager_employee_id,status,invited_at,activated_at,terminated_at,last_activity_at,created_at,updated_at)
      VALUES (?,?,NULL,?,?,?,NULL,?, ?,NULL,?,?,'INVITED',?,NULL,NULL,NULL,?,?)`)
      .bind(id, organisation.id, employee.employeeNumber, employee.fullName, employee.email, employee.jobTitleId, employee.departmentId, employee.branchId, employee.managerEmployeeId, now, now, now),
    db.prepare(`UPDATE license_usage SET reserved_value=reserved_value+1,version=version+1,updated_at=?
      WHERE organisation_license_id=? AND metric_key='USER_SEATS'`).bind(now, license.id),
    db.prepare(`INSERT INTO outbox_events
      (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), "EMPLOYEE", id, "EmployeeInvitationRecorded", 1, organisation.id, JSON.stringify({ employee_id: id, delivery: "DISABLED_LOCAL_STAGING" }), "PENDING", 0, now, now, null, null),
    await appendAudit(db, actor, "EMPLOYEE_INVITED", "EMPLOYEE", id, { organisationId: organisation.id, email: employee.email, delivery: "DISABLED_LOCAL_STAGING" }),
  ]);
  return { id, ...employee, status: "INVITED", invitationDelivery: "DISABLED_LOCAL_STAGING" };
}

export async function createOrganisationRole(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const role = normalizeOrganisationRole(input);
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 1, requestedOrganisationId);
  const db = await ensureDatabase();
  const catalogue = await db.prepare(`SELECT code FROM access_permissions WHERE code IN (${role.permissions.map(() => "?").join(",")})`)
    .bind(...role.permissions).all<{ code: string }>();
  if (catalogue.results.length !== role.permissions.length) throw new ControlPlaneValidationError("PERMISSION_UNKNOWN", "One or more permissions are not in the approved catalogue.");
  const prior = await db.prepare("SELECT MAX(version) AS version FROM organisation_roles WHERE organisation_id=? AND name=?")
    .bind(organisation.id, role.name).first<{ version: number | null }>();
  const version = (prior?.version ?? 0) + 1;
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const statements = [
    db.prepare(`INSERT INTO organisation_roles
      (id,organisation_id,name,description,version,branch_scope,approval_limit_cents,status,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind(id, organisation.id, role.name, role.description ?? "Organisation-defined least-privilege role.", version, JSON.stringify(role.branchScope), role.approvalLimitCents ?? null, "ACTIVE", actor.userId, now, now),
    ...role.permissions.map((permission) => db.prepare("INSERT INTO organisation_role_permissions VALUES (?,?,?,?,?,?)")
      .bind(crypto.randomUUID(), id, permission, "ORGANISATION", "ALLOW", now)),
    await appendAudit(db, actor, "ORGANISATION_ROLE_CREATED", "ORGANISATION_ROLE", id, { organisationId: organisation.id, name: role.name, version, permissions: role.permissions }),
  ];
  await db.batch(statements);
  return { id, ...role, version, status: "ACTIVE" };
}

export async function terminateEmployee(actor: UserContext, employeeId: string, reason: string, requestedOrganisationId?: string | null) {
  const { organisation, license } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const employee = await db.prepare("SELECT id,user_id,full_name,status FROM employees WHERE id=? AND organisation_id=?")
    .bind(employeeId, organisation.id).first<{ id: string; user_id: string | null; full_name: string; status: string }>();
  if (!employee) throw new ControlPlaneValidationError("EMPLOYEE_NOT_FOUND", "The employee is outside the active organisation scope.");
  if (employee.user_id === actor.userId) throw new ControlPlaneValidationError("SELF_OFFBOARD_DENIED", "Administrators cannot offboard their own privileged identity.");
  if (employee.status === "TERMINATED") return { id: employee.id, status: employee.status };
  const cleanReason = reason.trim();
  if (cleanReason.length < 5 || cleanReason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character offboarding reason.");
  const now = new Date().toISOString();
  const primary = await db.prepare(`SELECT user_id FROM organisation_administrators
    WHERE organisation_id=? AND is_primary=1 AND status='ACTIVE' AND user_id<>? LIMIT 1`).bind(organisation.id, employee.user_id ?? "__none__").first<{ user_id: string }>();
  const statements: D1PreparedStatement[] = [
    db.prepare("UPDATE employees SET status='TERMINATED',terminated_at=?,updated_at=? WHERE id=? AND organisation_id=?").bind(now, now, employee.id, organisation.id),
    db.prepare("UPDATE license_usage SET used_value=MAX(0,used_value-1),version=version+1,updated_at=? WHERE organisation_license_id=? AND metric_key='USER_SEATS'").bind(now, license.id),
    await appendAudit(db, actor, "EMPLOYEE_TERMINATED", "EMPLOYEE", employee.id, { organisationId: organisation.id, reason: cleanReason, historicalRecordsPreserved: true }),
  ];
  if (employee.user_id) {
    statements.push(
      db.prepare("UPDATE organisation_memberships SET status='REVOKED',valid_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, employee.user_id),
      db.prepare("UPDATE user_role_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, employee.user_id),
      db.prepare("UPDATE user_capability_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, employee.user_id),
      db.prepare("UPDATE app_users SET status='SUSPENDED' WHERE id=?").bind(employee.user_id),
    );
    if (primary) statements.push(db.prepare(`UPDATE workflow_assignments SET assigned_user_id=?
      WHERE assigned_user_id=? AND status='PENDING' AND workflow_instance_id IN (SELECT id FROM workflow_instances WHERE organisation_id=?)`)
      .bind(primary.user_id, employee.user_id, organisation.id));
  }
  await db.batch(statements);
  return { id: employee.id, status: "TERMINATED", historicalRecordsPreserved: true, tasksReassignedTo: primary?.user_id ?? null };
}

export async function createWorkflowDraft(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const definition = normalizeWorkflowDefinition(input);
  const { organisation, license } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "ADMIN_WRITE", 1, requestedOrganisationId);
  const db = await ensureDatabase();
  const duplicate = await db.prepare("SELECT id FROM workflows WHERE organisation_id=? AND name=?").bind(organisation.id, definition.name).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError("A workflow with this name already exists.");
  const workflowId = crypto.randomUUID();
  const versionId = crypto.randomUUID();
  const now = new Date().toISOString();
  const canonical = stableStringify(definition);
  const statements: D1PreparedStatement[] = [
    db.prepare("INSERT INTO workflows VALUES (?,?,?,?,?,?,?,?)").bind(workflowId, organisation.id, definition.name, definition.domainAction, "DRAFT", actor.userId, now, now),
    db.prepare(`INSERT INTO workflow_versions
      (id,workflow_id,organisation_id,version_number,status,definition_hash,definition,effective_from,published_by,approved_by,published_at,retired_at,created_at)
      VALUES (?,?,?,?,?,?,?,NULL,NULL,NULL,NULL,NULL,?)`).bind(versionId, workflowId, organisation.id, 1, "DRAFT", await sha256Hex(canonical), canonical, now),
    ...definition.nodes.map((node, index) => db.prepare("INSERT INTO workflow_nodes VALUES (?,?,?,?,?,?,?,?)")
      .bind(crypto.randomUUID(), versionId, node.id, node.type, node.label, node.assigneeType ?? null, node.assigneeRef ?? null, index + 1)),
  ];
  for (const [index, transition] of definition.transitions.entries()) {
    const transitionId = crypto.randomUUID();
    statements.push(db.prepare("INSERT INTO workflow_transitions VALUES (?,?,?,?,?)").bind(transitionId, versionId, transition.from, transition.to, index + 1));
    if (transition.condition) statements.push(db.prepare("INSERT INTO workflow_conditions VALUES (?,?,?,?,?)").bind(crypto.randomUUID(), transitionId, transition.condition.field, transition.condition.operator, String(transition.condition.value)));
  }
  statements.push(
    db.prepare("UPDATE license_usage SET reserved_value=reserved_value+1,version=version+1,updated_at=? WHERE organisation_license_id=? AND metric_key='WORKFLOWS'").bind(now, license.id),
    await appendAudit(db, actor, "WORKFLOW_DRAFT_CREATED", "WORKFLOW_VERSION", versionId, { organisationId: organisation.id, workflowId, domainAction: definition.domainAction }),
  );
  await db.batch(statements);
  return { workflowId, versionId, version: 1, status: "DRAFT", definition };
}

export async function publishWorkflowVersion(actor: UserContext, versionId: string, requestedOrganisationId?: string | null) {
  const { organisation, license } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const version = await db.prepare(`SELECT v.id,v.status,v.workflow_id,w.created_by FROM workflow_versions v JOIN workflows w ON w.id=v.workflow_id
    WHERE v.id=? AND v.organisation_id=?`).bind(versionId, organisation.id).first<{ id: string; status: string; workflow_id: string; created_by: string }>();
  if (!version) throw new ControlPlaneValidationError("WORKFLOW_VERSION_NOT_FOUND", "The workflow version is outside the active organisation scope.");
  if (version.status !== "DRAFT") throw new RepositoryConflictError("Only a draft workflow version can be published.");
  assertWorkflowDecision({ actorId: actor.userId, initiatedBy: version.created_by, decision: "APPROVE" });
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE workflow_versions SET status='PUBLISHED',effective_from=?,published_by=?,approved_by=?,published_at=? WHERE id=? AND status='DRAFT'").bind(now, actor.userId, actor.userId, now, version.id),
    db.prepare("UPDATE workflows SET status='ACTIVE',updated_at=? WHERE id=?").bind(now, version.workflow_id),
    db.prepare("UPDATE license_usage SET used_value=used_value+1,reserved_value=MAX(0,reserved_value-1),version=version+1,updated_at=? WHERE organisation_license_id=? AND metric_key='WORKFLOWS'").bind(now, license.id),
    await appendAudit(db, actor, "WORKFLOW_VERSION_PUBLISHED", "WORKFLOW_VERSION", version.id, { organisationId: organisation.id, creator: version.created_by, approver: actor.userId }),
  ]);
  return { id: version.id, status: "PUBLISHED", approvedBy: actor.userId, publishedAt: now };
}

export async function decideWorkflowTask(actor: UserContext, assignmentId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "BUSINESS_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const decision = String(source.decision ?? "").toUpperCase();
  const reason = String(source.reason ?? "").trim();
  if (reason.length < 5 || reason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character decision reason.");
  const db = await ensureDatabase();
  const task = await db.prepare(`SELECT a.id,a.status,a.assigned_user_id,i.id AS instance_id,i.initiated_by,i.workflow_version_id
    FROM workflow_assignments a JOIN workflow_instances i ON i.id=a.workflow_instance_id
    WHERE a.id=? AND i.organisation_id=?`).bind(assignmentId, organisation.id).first<{ id: string; status: string; assigned_user_id: string | null; instance_id: string; initiated_by: string; workflow_version_id: string }>();
  if (!task) throw new ControlPlaneValidationError("WORKFLOW_TASK_NOT_FOUND", "The workflow task is outside the active organisation scope.");
  if (task.status !== "PENDING") throw new RepositoryConflictError("The workflow task has already been decided.");
  try {
    assertWorkflowDecision({ actorId: actor.userId, initiatedBy: task.initiated_by, assignedUserId: task.assigned_user_id, decision, emergencyOverride: source.emergency_override === true });
  } catch (error) {
    if (error instanceof ControlPlaneValidationError && ["SELF_APPROVAL_DENIED", "EMERGENCY_OVERRIDE_DISABLED"].includes(error.code)) {
      const rule = await db.prepare("SELECT id FROM sod_rules WHERE organisation_id=? AND code='NO_SELF_APPROVAL' AND status='ACTIVE'").bind(organisation.id).first<{ id: string }>();
      if (rule) await db.prepare("INSERT INTO sod_violations VALUES (?,?,?,?,?,?,?,?,?,NULL)").bind(crypto.randomUUID(), organisation.id, rule.id, actor.userId, "WORKFLOW_ASSIGNMENT", assignmentId, "OPEN", JSON.stringify({ code: error.code }), new Date().toISOString()).run();
    }
    throw error;
  }
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO workflow_approvals VALUES (?,?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), task.instance_id, task.id, task.workflow_version_id, actor.userId, decision, reason, JSON.stringify({ role: actor.role, permissions: actor.dynamicPermissions }), now),
    db.prepare("UPDATE workflow_assignments SET status=? WHERE id=? AND status='PENDING'").bind(decision === "APPROVE" ? "APPROVED" : "REJECTED", task.id),
    db.prepare("UPDATE workflow_instances SET status=?,completed_at=? WHERE id=?").bind(decision === "APPROVE" ? "COMPLETED" : "REJECTED", now, task.instance_id),
    await appendAudit(db, actor, `WORKFLOW_${decision}`, "WORKFLOW_ASSIGNMENT", task.id, { organisationId: organisation.id, reason }),
  ]);
  return { id: task.id, decision, decidedAt: now };
}

export async function requestRoleAccess(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "BUSINESS_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const subjectUserId = String(source.subject_user_id ?? actor.userId);
  const roleId = String(source.role_id ?? "");
  const justification = String(source.justification ?? "").trim();
  if (justification.length < 10 || justification.length > 400) throw new ControlPlaneValidationError("JUSTIFICATION_REQUIRED", "Provide a 10 to 400 character access justification.");
  const db = await ensureDatabase();
  const [subject, role] = await Promise.all([
    db.prepare("SELECT user_id FROM organisation_memberships WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, subjectUserId).first<{ user_id: string }>(),
    db.prepare("SELECT id FROM organisation_roles WHERE organisation_id=? AND id=? AND status='ACTIVE'").bind(organisation.id, roleId).first<{ id: string }>(),
  ]);
  if (!subject || !role) throw new ControlPlaneValidationError("ACCESS_REFERENCE_INVALID", "The subject or role is outside the active organisation.");
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO access_requests VALUES (?,?,?,?,?,?,?,?,NULL)").bind(id, organisation.id, actor.userId, subjectUserId, roleId, justification, "PENDING_MANAGER", now),
    await appendAudit(db, actor, "ACCESS_REQUESTED", "ACCESS_REQUEST", id, { organisationId: organisation.id, subjectUserId, roleId }),
  ]);
  return { id, status: "PENDING_MANAGER", requestedAt: now };
}

export async function decideAccessRequest(actor: UserContext, requestId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "ADMIN_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const decision = String(source.decision ?? "").toUpperCase();
  const reason = String(source.reason ?? "").trim();
  if (!['APPROVE','REJECT'].includes(decision)) throw new ControlPlaneValidationError("DECISION_INVALID", "Access decisions must be APPROVE or REJECT.");
  if (reason.length < 5 || reason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character decision reason.");
  const db = await ensureDatabase();
  const access = await db.prepare("SELECT * FROM access_requests WHERE id=? AND organisation_id=?").bind(requestId, organisation.id).first<{ id: string; requested_by: string; subject_user_id: string; organisation_role_id: string; status: string }>();
  if (!access) throw new ControlPlaneValidationError("ACCESS_REQUEST_NOT_FOUND", "The access request is outside the active organisation.");
  if (access.status !== "PENDING_MANAGER") throw new RepositoryConflictError("The access request has already been decided.");
  if (actor.userId === access.requested_by || actor.userId === access.subject_user_id) throw new ControlPlaneValidationError("SELF_APPROVAL_DENIED", "A requester or access subject cannot approve their own access request.");
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
    db.prepare("INSERT INTO access_approvals VALUES (?,?,?,?,?,?,?)").bind(crypto.randomUUID(), access.id, actor.userId, "MANAGER", decision, reason, now),
    db.prepare("UPDATE access_requests SET status=?,completed_at=? WHERE id=?").bind(decision === "APPROVE" ? "APPROVED" : "REJECTED", now, access.id),
    await appendAudit(db, actor, `ACCESS_${decision}`, "ACCESS_REQUEST", access.id, { organisationId: organisation.id, reason }),
  ];
  if (decision === "APPROVE") statements.push(db.prepare("INSERT INTO user_role_assignments VALUES (?,?,?,?,?,?,?,?,?,?)")
    .bind(crypto.randomUUID(), organisation.id, access.subject_user_id, null, access.organisation_role_id, "ACTIVE", now, null, actor.userId, now));
  await db.batch(statements);
  return { id: access.id, status: decision === "APPROVE" ? "APPROVED" : "REJECTED", decidedAt: now };
}

export async function openQuarterlyAccessReview(actor: UserContext, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "COMPLIANCE_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const window = quarterlyAccessReviewWindow();
  const existing = await db.prepare("SELECT id,status,due_at FROM access_reviews WHERE organisation_id=? AND review_type='QUARTERLY' AND period_start=?")
    .bind(organisation.id, window.periodStart).first<{ id: string; status: string; due_at: string }>();
  if (existing) return { ...existing, periodStart: window.periodStart };
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO access_reviews VALUES (?,?,?,?,?,?,?,?,?,NULL)").bind(id, organisation.id, `${window.key} privileged and dormant access review`, "QUARTERLY", "OPEN", window.periodStart, window.dueAt, actor.userId, now),
    await appendAudit(db, actor, "QUARTERLY_ACCESS_REVIEW_OPENED", "ACCESS_REVIEW", id, { organisationId: organisation.id, period: window.key, dueAt: window.dueAt }),
  ]);
  return { id, status: "OPEN", periodStart: window.periodStart, dueAt: window.dueAt };
}

export async function certifyQuarterlyAccess(actor: UserContext, reviewId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "COMPLIANCE_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const subjectUserId = String(source.subject_user_id ?? "");
  const disposition = String(source.disposition ?? "").toUpperCase();
  const finding = String(source.finding ?? "").trim();
  if (!['RETAIN','REVOKE'].includes(disposition)) throw new ControlPlaneValidationError("DISPOSITION_INVALID", "Access certification must RETAIN or REVOKE access.");
  if (actor.userId === subjectUserId) throw new ControlPlaneValidationError("SELF_CERTIFICATION_DENIED", "Users cannot certify their own quarterly access.");
  if (finding.length > 400) throw new ControlPlaneValidationError("FINDING_INVALID", "Access-review findings cannot exceed 400 characters.");
  const db = await ensureDatabase();
  const [review, subject, roles, administration] = await Promise.all([
    db.prepare("SELECT id,status FROM access_reviews WHERE id=? AND organisation_id=? AND review_type='QUARTERLY'").bind(reviewId, organisation.id).first<{ id: string; status: string }>(),
    db.prepare("SELECT user_id,role_code FROM organisation_memberships WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, subjectUserId).first<{ user_id: string; role_code: string }>(),
    db.prepare(`SELECT r.name FROM user_role_assignments a JOIN organisation_roles r ON r.id=a.organisation_role_id
      WHERE a.organisation_id=? AND a.user_id=? AND a.status='ACTIVE'`).bind(organisation.id, subjectUserId).all<{ name: string }>(),
    db.prepare("SELECT administrator_role_code FROM organisation_administrators WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, subjectUserId).all<{ administrator_role_code: string }>(),
  ]);
  if (!review || review.status !== "OPEN") throw new RepositoryConflictError("The quarterly access review is not open.");
  if (!subject) throw new ControlPlaneValidationError("SUBJECT_NOT_ACTIVE", "The certification subject has no active organisation membership.");
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const snapshot = JSON.stringify({ baseRole: subject.role_code, organisationRoles: roles.results.map((item) => item.name), administratorRoles: administration.results.map((item) => item.administrator_role_code) });
  const statements: D1PreparedStatement[] = [
    db.prepare("INSERT INTO access_certifications VALUES (?,?,?,?,?,?,?,?,?)").bind(id, review.id, organisation.id, subjectUserId, actor.userId, snapshot, disposition, finding || null, now),
    db.prepare(`UPDATE access_reviews SET status='COMPLETED',completed_at=? WHERE id=? AND
      (SELECT COUNT(*) FROM access_certifications WHERE access_review_id=?) >=
      (SELECT COUNT(*) FROM organisation_memberships WHERE organisation_id=? AND status='ACTIVE')`).bind(now, review.id, review.id, organisation.id),
    await appendAudit(db, actor, `ACCESS_CERTIFIED_${disposition}`, "ACCESS_REVIEW", review.id, { organisationId: organisation.id, subjectUserId, finding }),
  ];
  if (disposition === "REVOKE") statements.push(
    db.prepare("UPDATE organisation_memberships SET status='REVOKED',valid_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, subjectUserId),
    db.prepare("UPDATE user_role_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, subjectUserId),
    db.prepare("UPDATE user_capability_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, subjectUserId),
  );
  await db.batch(statements);
  return { id, reviewId: review.id, subjectUserId, disposition, certifiedAt: now };
}

export async function searchWorkspace(actor: UserContext, query: string, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "READ", 0, requestedOrganisationId);
  if (!hasPermission(actor, "search:read")) throw new AccessDeniedError("Workspace search is not authorised.");
  const term = query.trim().slice(0, 80);
  if (term.length < 2) return [];
  const db = await ensureDatabase();
  const like = `%${term.replaceAll("%", "").replaceAll("_", "")}%`;
  const results: Array<{ type: string; id: string; title: string; subtitle: string; href: string }> = [];
  if (hasPermission(actor, "employees:read")) {
    const rows = await db.prepare("SELECT id,full_name,email,employee_number FROM employees WHERE organisation_id=? AND (full_name LIKE ? OR email LIKE ? OR employee_number LIKE ?) LIMIT 15")
      .bind(organisation.id, like, like, like).all<{ id: string; full_name: string; email: string; employee_number: string }>();
    results.push(...rows.results.map((row) => ({ type: "Employee", id: row.id, title: row.full_name, subtitle: `${row.employee_number} · ${row.email}`, href: "/administration#employees" })));
  }
  if (hasPermission(actor, "invoices:read")) {
    const rows = await db.prepare(`SELECT id,invoice_number,supplier_name,customer_name FROM invoices
      WHERE (supplier_taxpayer_id=? OR customer_taxpayer_id=?) AND (invoice_number LIKE ? OR supplier_name LIKE ? OR customer_name LIKE ?) LIMIT 15`)
      .bind(organisation.taxpayer_id, organisation.taxpayer_id, like, like, like).all<{ id: string; invoice_number: string; supplier_name: string; customer_name: string }>();
    results.push(...rows.results.map((row) => ({ type: "Invoice", id: row.id, title: row.invoice_number, subtitle: `${row.supplier_name} → ${row.customer_name}`, href: `/invoices/${row.id}` })));
  }
  if (hasPermission(actor, "roles:read")) {
    const rows = await db.prepare("SELECT id,name,description FROM organisation_roles WHERE organisation_id=? AND (name LIKE ? OR description LIKE ?) LIMIT 15")
      .bind(organisation.id, like, like).all<{ id: string; name: string; description: string }>();
    results.push(...rows.results.map((row) => ({ type: "Role", id: row.id, title: row.name, subtitle: row.description, href: "/administration#roles" })));
  }
  return results.slice(0, 30);
}
