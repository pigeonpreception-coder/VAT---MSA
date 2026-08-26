import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, hasPermission, isNationalScope } from "@/lib/auth";
import {
  ControlPlaneValidationError,
  evaluateEntitlement,
  evaluateWorkflowCondition,
  assertLicenseStateTransition,
  assertWorkflowDecision,
  normalizeAccessRevocation,
  normalizeAdministratorAppointment,
  normalizeCapabilityGrant,
  normalizeDelegation,
  normalizeEmployee,
  normalizeEmployeeActivation,
  normalizeLicenseStateChange,
  normalizeLicenseUpgrade,
  normalizeNavigationChildrenQuery,
  normalizeNavigationPreference,
  normalizeOffboarding,
  normalizeOrganisationRole,
  normalizeWorkflowAssignment,
  normalizeWorkflowDefinition,
  normalizeWorkflowTestContext,
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

/** Licensing & Entitlements standalone GetEntitlements — previously readable only bundled inside getAdministrationSnapshot. */
export async function getEntitlementsSnapshot(actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  return {
    organisation,
    license: { ...license, price: null, pricingConfigured: false },
    entitlements: await getEntitlements(db, license),
  };
}

/** Licensing & Entitlements standalone GetUsage — previously not queryable at all outside the administration snapshot's bundled entitlement view. */
export async function getUsageSnapshot(actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const usage = await db.prepare(`SELECT metric_key,period_key,used_value,reserved_value,version,updated_at
    FROM license_usage WHERE organisation_license_id=? ORDER BY metric_key`).bind(license.id)
    .all<{ metric_key: string; period_key: string; used_value: number; reserved_value: number; version: number; updated_at: string }>();
  return { organisation, licenseId: license.id, usage: usage.results };
}

const LICENSE_STATE_EVENT_TYPE: Record<"ACTIVATE" | "SUSPEND" | "RENEW", string> = {
  ACTIVATE: "LICENSE_ACTIVATED",
  SUSPEND: "LICENSE_SUSPENDED",
  RENEW: "LICENSE_RENEWED",
};

/**
 * Licensing & Entitlements Activate/Suspend/Renew. Combined into one command
 * (three actions, one state-machine shape) since license_events already
 * models every transition as from_state/to_state/authority/reason — nothing
 * previously wrote to that table despite it existing since migration 0008.
 */
export async function changeLicenseState(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const { action, reason } = normalizeLicenseStateChange(input);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  assertLicenseStateTransition(action, license.state);
  const toState: LicenseState = action === "SUSPEND" ? "SUSPENDED" : "ACTIVE";
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
    db.prepare("UPDATE organisation_licenses SET state=?,state_version=state_version+1,updated_at=? WHERE id=?").bind(toState, now, license.id),
    db.prepare(`INSERT INTO license_events (id,organisation_license_id,organisation_id,event_type,from_state,to_state,authority,reason,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), license.id, organisation.id, LICENSE_STATE_EVENT_TYPE[action], license.state, toState, actor.userId, reason, now),
  ];
  if (action === "RENEW") {
    const currentEndMs = Date.parse(license.current_period_end);
    const baseMs = Number.isFinite(currentEndMs) ? Math.max(currentEndMs, Date.now()) : Date.now();
    const newStart = new Date(baseMs);
    const newEnd = new Date(baseMs);
    newEnd.setUTCFullYear(newEnd.getUTCFullYear() + 1);
    statements.push(
      db.prepare(`UPDATE subscriptions SET current_period_start=?,current_period_end=?,updated_at=?
        WHERE id=(SELECT subscription_id FROM organisation_licenses WHERE id=?)`)
        .bind(newStart.toISOString(), newEnd.toISOString(), now, license.id),
    );
  }
  statements.push(await appendAudit(db, actor, LICENSE_STATE_EVENT_TYPE[action], "ORGANISATION_LICENSE", license.id, { organisationId: organisation.id, fromState: license.state, toState, reason }));
  await db.batch(statements);
  return { licenseId: license.id, state: toState, previousState: license.state };
}

/**
 * Licensing & Entitlements Upgrade: a distinct plan-change operation, not a
 * state transition. Closes the current organisation_licenses row
 * (effective_to=now) and inserts a new one on the target plan — a versioned
 * history of plan changes, not an in-place mutation, matching how the rest
 * of this repository treats structural records.
 */
export async function upgradeLicense(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const { licensePlanCode } = normalizeLicenseUpgrade(input);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  if (licensePlanCode === license.plan_code) {
    throw new ControlPlaneValidationError("LICENSE_PLAN_UNCHANGED", "The organisation is already on this licence plan.");
  }
  if (!["ACTIVE", "TRIAL"].includes(license.state)) {
    throw new ControlPlaneValidationError("LICENSE_TRANSITION_INVALID", `Cannot upgrade a licence currently in state ${license.state}.`);
  }
  const targetPlan = await db.prepare("SELECT id,code,name,version FROM license_plans WHERE code=? AND status='ACTIVE' ORDER BY version DESC LIMIT 1")
    .bind(licensePlanCode).first<{ id: string; code: string; name: string; version: number }>();
  if (!targetPlan) throw new ControlPlaneValidationError("LICENSE_PLAN_NOT_FOUND", "The requested licence plan is not available.");

  const now = new Date().toISOString();
  const newLicenseId = crypto.randomUUID();
  const statements: D1PreparedStatement[] = [
    db.prepare("UPDATE organisation_licenses SET effective_to=? WHERE id=?").bind(now, license.id),
    db.prepare(`INSERT INTO organisation_licenses (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
      VALUES (?,?,(SELECT subscription_id FROM organisation_licenses WHERE id=?),?,?,?,?,NULL,NULL,?,?)`)
      .bind(newLicenseId, organisation.id, license.id, targetPlan.id, "ACTIVE", 1, now, license.retention_policy, now),
    db.prepare(`INSERT INTO license_events (id,organisation_license_id,organisation_id,event_type,from_state,to_state,authority,reason,occurred_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), newLicenseId, organisation.id, "LICENSE_PLAN_UPGRADED", license.state, "ACTIVE", actor.userId, `Upgraded from ${license.plan_code} to ${targetPlan.code}`, now),
    await appendAudit(db, actor, "LICENSE_PLAN_UPGRADED", "ORGANISATION_LICENSE", newLicenseId, { organisationId: organisation.id, fromPlan: license.plan_code, toPlan: targetPlan.code }),
  ];
  await db.batch(statements);
  return { licenseId: newLicenseId, planCode: targetPlan.code, planName: targetPlan.name, state: "ACTIVE" };
}

type NavigationAccessContext = { enabledFeatures: Set<string>; capabilitySet: Set<string> };
type NavigationGate = { feature_key?: string | null; capability?: string | null; required_permission: string };

async function getNavigationAccessContext(db: D1Database, actor: UserContext, organisation: OrganisationScope, license: LicenseRow): Promise<NavigationAccessContext> {
  const [entitlements, capabilities] = await Promise.all([
    getEntitlements(db, license),
    db.prepare("SELECT capability FROM organisation_capabilities WHERE organisation_id=? AND status='ACTIVE'").bind(organisation.id).all<{ capability: string }>(),
  ]);
  return {
    enabledFeatures: new Set(entitlements.filter((item) => item.enabled === 1).map((item) => item.feature_key)),
    capabilitySet: new Set([
      ...capabilities.results.map((item) => item.capability),
      ...actor.organisationId === organisation.id ? actor.capabilities : [],
    ]),
  };
}

function navigationRowAllowed(actor: UserContext, row: NavigationGate, context: NavigationAccessContext): boolean {
  if (!hasPermission(actor, row.required_permission)) return false;
  if (row.feature_key && !context.enabledFeatures.has(row.feature_key)) return false;
  if (row.capability && !context.capabilitySet.has(row.capability)) return false;
  return true;
}

export async function getEffectiveNavigation(actor: UserContext, requestedOrganisationId?: string | null): Promise<{ organisation: OrganisationScope; workspaces: NavigationWorkspace[] }> {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const [context, rows] = await Promise.all([
    getNavigationAccessContext(db, actor, organisation, license),
    db.prepare(`SELECT w.id AS workspace_id,w.workspace_key,w.label AS workspace_label,w.description,w.classification AS workspace_classification,
      f.id AS folder_id,f.folder_key,f.label AS folder_label,
      i.id AS item_id,i.item_key,i.label AS item_label,i.href,i.feature_key,i.capability,i.required_permission,i.classification AS item_classification
      FROM navigation_workspaces w JOIN navigation_folders f ON f.workspace_id=w.id AND f.status='ACTIVE'
      JOIN navigation_items i ON i.folder_id=f.id AND i.status='ACTIVE'
      WHERE w.status='ACTIVE' ORDER BY w.sort_order,f.sort_order,i.sort_order`).all<Record<string, string | null>>(),
  ]);
  const byWorkspace = new Map<string, NavigationWorkspace>();
  const folderIndex = new Map<string, NavigationFolder>();
  for (const row of rows.results) {
    if (!navigationRowAllowed(actor, { feature_key: row.feature_key, capability: row.capability, required_permission: String(row.required_permission) }, context)) continue;
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

/**
 * Workspace & Navigation GetChildren: a scoped drill-down instead of
 * fetching the whole tree via GetEffectiveNavigation — a workspace's
 * top-level folders, or one folder's sub-folders and items, properly
 * respecting navigation_folders.parent_folder_id (which GetEffectiveNavigation's
 * flat query does not traverse).
 */
export async function getNavigationChildren(actor: UserContext, parentType: unknown, parentId: unknown, requestedOrganisationId?: string | null) {
  const query = normalizeNavigationChildrenQuery(parentType, parentId);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const context = await getNavigationAccessContext(db, actor, organisation, license);

  if (query.parentType === "workspace") {
    const workspace = await db.prepare("SELECT id,workspace_key,label,description,classification FROM navigation_workspaces WHERE id=? AND status='ACTIVE'")
      .bind(query.parentId).first<{ id: string; workspace_key: string; label: string; description: string; classification: string }>();
    if (!workspace) throw new ControlPlaneValidationError("WORKSPACE_NOT_FOUND", "The navigation workspace does not exist.");
    const folders = await db.prepare(`SELECT id,folder_key,label FROM navigation_folders
      WHERE workspace_id=? AND parent_folder_id IS NULL AND status='ACTIVE' ORDER BY sort_order`).bind(workspace.id)
      .all<{ id: string; folder_key: string; label: string }>();
    return {
      parentType: "workspace" as const,
      workspace: { id: workspace.id, key: workspace.workspace_key, label: workspace.label, description: workspace.description, classification: workspace.classification },
      folders: folders.results.map((f) => ({ id: f.id, key: f.folder_key, label: f.label })),
    };
  }

  const folder = await db.prepare("SELECT id,workspace_id,folder_key,label FROM navigation_folders WHERE id=? AND status='ACTIVE'")
    .bind(query.parentId).first<{ id: string; workspace_id: string; folder_key: string; label: string }>();
  if (!folder) throw new ControlPlaneValidationError("FOLDER_NOT_FOUND", "The navigation folder does not exist.");
  const [subfolders, items] = await Promise.all([
    db.prepare("SELECT id,folder_key,label FROM navigation_folders WHERE parent_folder_id=? AND status='ACTIVE' ORDER BY sort_order")
      .bind(folder.id).all<{ id: string; folder_key: string; label: string }>(),
    db.prepare(`SELECT id,item_key,label,href,feature_key,capability,required_permission,classification
      FROM navigation_items WHERE folder_id=? AND status='ACTIVE' ORDER BY sort_order`).bind(folder.id)
      .all<{ id: string; item_key: string; label: string; href: string; feature_key: string | null; capability: string | null; required_permission: string; classification: string }>(),
  ]);
  return {
    parentType: "folder" as const,
    folder: { id: folder.id, key: folder.folder_key, label: folder.label },
    folders: subfolders.results.map((f) => ({ id: f.id, key: f.folder_key, label: f.label })),
    items: items.results.filter((item) => navigationRowAllowed(actor, item, context))
      .map((item) => ({ id: item.id, key: item.item_key, label: item.label, href: item.href, classification: item.classification })),
  };
}

/**
 * Workspace & Navigation GetActions: whether the actor can act on one
 * specific navigation item right now, and why not if not — for a route
 * guard or a disabled-nav-item tooltip, without walking the whole tree.
 */
export async function getNavigationItemActions(actor: UserContext, itemKey: unknown, requestedOrganisationId?: string | null) {
  const key = String(itemKey ?? "").trim();
  if (!key) throw new ControlPlaneValidationError("ITEM_KEY_REQUIRED", "item_key is required.");
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const license = await getLicense(db, organisation.id);
  const context = await getNavigationAccessContext(db, actor, organisation, license);
  const item = await db.prepare(`SELECT id,item_key,label,href,feature_key,capability,required_permission,classification
    FROM navigation_items WHERE item_key=? AND status='ACTIVE'`).bind(key)
    .first<{ id: string; item_key: string; label: string; href: string; feature_key: string | null; capability: string | null; required_permission: string; classification: string }>();
  if (!item) throw new ControlPlaneValidationError("NAVIGATION_ITEM_NOT_FOUND", "The navigation item does not exist.");
  const deniedReasons: string[] = [];
  if (!hasPermission(actor, item.required_permission)) deniedReasons.push(`Requires permission ${item.required_permission}.`);
  if (item.feature_key && !context.enabledFeatures.has(item.feature_key)) deniedReasons.push(`Requires licensed feature ${item.feature_key}.`);
  if (item.capability && !context.capabilitySet.has(item.capability)) deniedReasons.push(`Requires ${item.capability} capability.`);
  const allowed = deniedReasons.length === 0;
  return {
    id: item.id, key: item.item_key, label: item.label, href: item.href, classification: item.classification,
    allowed,
    actions: allowed ? [{ action: "VIEW", href: item.href }] : [],
    deniedReasons,
  };
}

/**
 * Workspace & Navigation SavePreference. A low-risk, self-scoped write
 * (always the caller's own preference) — deliberately skips the audit_events/
 * outbox_events machinery every other mutating command here uses, since a UI
 * preference like a collapsed sidebar isn't a privileged or statutory action.
 * Upserts via the (user_id, organisation_id, preference_type) unique index.
 */
export async function saveNavigationPreference(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const preference = normalizeNavigationPreference(input);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const now = new Date().toISOString();
  await db.prepare(`INSERT INTO navigation_preferences (id,user_id,organisation_id,preference_type,value,updated_at)
    VALUES (?,?,?,?,?,?)
    ON CONFLICT(user_id,organisation_id,preference_type) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`)
    .bind(crypto.randomUUID(), actor.userId, organisation.id, preference.preferenceType, preference.value, now).run();
  return { userId: actor.userId, organisationId: organisation.id, preferenceType: preference.preferenceType, value: JSON.parse(preference.value) as unknown };
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

/**
 * Organisation Administration employee INVITED -> ACTIVE. Links the invited
 * employee record to an existing, already-active app_users row and converts
 * the USER_SEATS licence reservation inviteEmployee made into actual usage
 * (mirrors terminateEmployee's used_value decrement on the way out).
 * Idempotent on an already-ACTIVE employee.
 */
export async function activateEmployee(actor: UserContext, employeeId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { userId } = normalizeEmployeeActivation(input);
  const { organisation, license } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const employee = await db.prepare("SELECT id,status,user_id FROM employees WHERE id=? AND organisation_id=?")
    .bind(employeeId, organisation.id).first<{ id: string; status: string; user_id: string | null }>();
  if (!employee) throw new ControlPlaneValidationError("EMPLOYEE_NOT_FOUND", "The employee is outside the active organisation scope.");
  if (employee.status === "ACTIVE") return { id: employee.id, status: "ACTIVE", userId: employee.user_id };
  if (employee.status !== "INVITED") throw new ControlPlaneValidationError("EMPLOYEE_NOT_INVITED", `Cannot activate an employee currently ${employee.status}.`);

  const targetUser = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(userId).first<{ id: string; status: string }>();
  if (!targetUser) throw new ControlPlaneValidationError("USER_NOT_FOUND", "The target user does not exist.");
  if (targetUser.status !== "ACTIVE") throw new ControlPlaneValidationError("USER_NOT_ACTIVE", "The target user is not active.");
  const alreadyLinked = await db.prepare("SELECT id FROM employees WHERE organisation_id=? AND user_id=? AND status='ACTIVE'")
    .bind(organisation.id, userId).first<{ id: string }>();
  if (alreadyLinked) throw new RepositoryConflictError("This user is already linked to an active employee record in this organisation.");

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE employees SET user_id=?,status='ACTIVE',activated_at=?,updated_at=? WHERE id=? AND organisation_id=?").bind(userId, now, now, employee.id, organisation.id),
    db.prepare(`UPDATE license_usage SET used_value=used_value+1,reserved_value=MAX(0,reserved_value-1),version=version+1,updated_at=?
      WHERE organisation_license_id=? AND metric_key='USER_SEATS'`).bind(now, license.id),
    await appendAudit(db, actor, "EMPLOYEE_ACTIVATED", "EMPLOYEE", employee.id, { organisationId: organisation.id, userId }),
  ]);
  return { id: employee.id, status: "ACTIVE", userId };
}

/**
 * Organisation Administration AppointAdministrator. Requires the target to
 * already be an active employee of this organisation — administrators are
 * always grounded in a real employee record, matching the seed data
 * pattern. Appointing a new primary administrator demotes any existing one:
 * the architecture models exactly one primary Organisation Portal
 * Administrator per organisation.
 */
export async function appointAdministrator(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const appointment = normalizeAdministratorAppointment(input);
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 1, requestedOrganisationId);
  const db = await ensureDatabase();
  const role = await db.prepare("SELECT code,name FROM organisation_administrator_roles WHERE code=?").bind(appointment.administratorRoleCode).first<{ code: string; name: string }>();
  if (!role) throw new ControlPlaneValidationError("ADMINISTRATOR_ROLE_NOT_FOUND", "The administrator role is not in the approved catalogue.");
  const employee = await db.prepare("SELECT id FROM employees WHERE organisation_id=? AND user_id=? AND status='ACTIVE'")
    .bind(organisation.id, appointment.userId).first<{ id: string }>();
  if (!employee) throw new ControlPlaneValidationError("EMPLOYEE_NOT_ACTIVE", "The target user must be an active employee of this organisation before appointment.");
  const existing = await db.prepare("SELECT id FROM organisation_administrators WHERE organisation_id=? AND user_id=? AND administrator_role_code=? AND status='ACTIVE'")
    .bind(organisation.id, appointment.userId, appointment.administratorRoleCode).first<{ id: string }>();
  if (existing) throw new RepositoryConflictError("This user already holds this administrator role.");

  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const statements: D1PreparedStatement[] = [];
  if (appointment.isPrimary) {
    statements.push(db.prepare("UPDATE organisation_administrators SET is_primary=0 WHERE organisation_id=? AND is_primary=1 AND status='ACTIVE'").bind(organisation.id));
  }
  statements.push(
    db.prepare(`INSERT INTO organisation_administrators (id,organisation_id,user_id,employee_id,administrator_role_code,scope,is_primary,status,effective_from,effective_to,appointed_by,approval_reference)
      VALUES (?,?,?,?,?,?,?,?,?,NULL,?,?)`)
      .bind(id, organisation.id, appointment.userId, employee.id, appointment.administratorRoleCode, JSON.stringify({ organisation_id: organisation.id }), appointment.isPrimary ? 1 : 0, "ACTIVE", now, actor.userId, appointment.approvalReference),
    await appendAudit(db, actor, "ADMINISTRATOR_APPOINTED", "ORGANISATION_ADMINISTRATOR", id, { organisationId: organisation.id, userId: appointment.userId, role: appointment.administratorRoleCode, isPrimary: appointment.isPrimary }),
  );
  await db.batch(statements);
  return { id, organisationId: organisation.id, userId: appointment.userId, administratorRoleCode: appointment.administratorRoleCode, isPrimary: appointment.isPrimary, status: "ACTIVE" };
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

/** Organisation Authorization standalone read of user_capability_assignments — no prior read of this table existed outside the internal buildUserContext join. */
export async function listCapabilityGrants(actor: UserContext, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "READ", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT uca.id,uca.user_id,u.display_name,u.email,uca.capability,uca.status,uca.effective_from,uca.effective_to
    FROM user_capability_assignments uca JOIN app_users u ON u.id=uca.user_id
    WHERE uca.organisation_id=? ORDER BY u.display_name,uca.capability`).bind(organisation.id)
    .all<Record<string, string | null>>();
  return { organisation, capabilities: result.results };
}

/**
 * Organisation Authorization GrantCapability. Requires the organisation
 * itself to already hold the capability (organisation_capabilities) and the
 * target to be an active member — this only ever narrows visibility within
 * what the organisation is already entitled to, never grants something the
 * org itself doesn't hold. Upserts rather than blind-inserts: the unique
 * index on (organisation_id, user_id, capability) means a prior revoked
 * grant must be reactivated in place, not duplicated.
 */
export async function grantCapability(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const grant = normalizeCapabilityGrant(input);
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();

  const targetUser = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(grant.userId).first<{ id: string; status: string }>();
  if (!targetUser) throw new ControlPlaneValidationError("USER_NOT_FOUND", "The target user does not exist.");
  if (targetUser.status !== "ACTIVE") throw new ControlPlaneValidationError("USER_NOT_ACTIVE", "The target user is not active.");

  const orgCapability = await db.prepare(`SELECT id FROM organisation_capabilities
    WHERE organisation_id=? AND capability=? AND status='ACTIVE'
      AND datetime(effective_from)<=CURRENT_TIMESTAMP AND (effective_to IS NULL OR datetime(effective_to)>CURRENT_TIMESTAMP)`)
    .bind(organisation.id, grant.capability).first<{ id: string }>();
  if (!orgCapability) throw new ControlPlaneValidationError("ORGANISATION_CAPABILITY_INACTIVE", `The organisation does not currently hold ${grant.capability} capability.`);

  const membership = await db.prepare("SELECT id FROM organisation_memberships WHERE organisation_id=? AND user_id=? AND status='ACTIVE'")
    .bind(organisation.id, grant.userId).first<{ id: string }>();
  if (!membership) throw new ControlPlaneValidationError("USER_NOT_MEMBER", "The target user is not an active member of this organisation.");

  const existing = await db.prepare("SELECT id,status FROM user_capability_assignments WHERE organisation_id=? AND user_id=? AND capability=?")
    .bind(organisation.id, grant.userId, grant.capability).first<{ id: string; status: string }>();
  if (existing?.status === "ACTIVE") {
    return { id: existing.id, organisationId: organisation.id, userId: grant.userId, capability: grant.capability, status: "ACTIVE" };
  }

  const now = new Date().toISOString();
  const id = existing?.id ?? crypto.randomUUID();
  const statements: D1PreparedStatement[] = [
    existing
      ? db.prepare("UPDATE user_capability_assignments SET status='ACTIVE',effective_from=?,effective_to=NULL,assigned_by=? WHERE id=?").bind(now, actor.userId, id)
      : db.prepare(`INSERT INTO user_capability_assignments (id,organisation_id,user_id,capability,status,effective_from,effective_to,assigned_by)
          VALUES (?,?,?,?,?,?,NULL,?)`).bind(id, organisation.id, grant.userId, grant.capability, "ACTIVE", now, actor.userId),
    await appendAudit(db, actor, "CAPABILITY_GRANTED", "USER_CAPABILITY_ASSIGNMENT", id, { organisationId: organisation.id, userId: grant.userId, capability: grant.capability }),
  ];
  await db.batch(statements);
  return { id, organisationId: organisation.id, userId: grant.userId, capability: grant.capability, status: "ACTIVE" };
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

type NextNodeResolution = { nodeKey: string; nodeType: string; label: string; assigneeType: string | null; assigneeReference: string | null };

/**
 * Module 8 Phase C: shared transition-graph traversal — the one place a
 * next node is ever resolved, used identically by Assign (advancing off
 * START), Decide (advancing off the just-decided APPROVAL node) and Test
 * (a full dry-run path). Picks the first transition (by `sequence`) whose
 * condition, if any, matches the supplied context; a transition with no
 * condition always matches.
 */
async function resolveNextNode(db: D1Database, versionId: string, fromNodeKey: string, context: Record<string, unknown>): Promise<NextNodeResolution | null> {
  const transitions = await db.prepare("SELECT id,to_node_key FROM workflow_transitions WHERE workflow_version_id=? AND from_node_key=? ORDER BY sequence")
    .bind(versionId, fromNodeKey).all<{ id: string; to_node_key: string }>();
  for (const transition of transitions.results) {
    const conditions = await db.prepare("SELECT field,operator,comparison_value FROM workflow_conditions WHERE workflow_transition_id=?")
      .bind(transition.id).all<{ field: string; operator: string; comparison_value: string }>();
    if (!conditions.results.every((condition) => evaluateWorkflowCondition(condition, context))) continue;
    const node = await db.prepare("SELECT node_key,node_type,label,assignee_type,assignee_reference FROM workflow_nodes WHERE workflow_version_id=? AND node_key=?")
      .bind(versionId, transition.to_node_key).first<{ node_key: string; node_type: string; label: string; assignee_type: string | null; assignee_reference: string | null }>();
    if (!node) continue;
    return { nodeKey: node.node_key, nodeType: node.node_type, label: node.label, assigneeType: node.assignee_type, assigneeReference: node.assignee_reference };
  }
  return null;
}

/**
 * Module 8 Phase C: redirects a resolved assignee through an ACTIVE
 * delegation, if one covers this workflow (or ALL workflows) and is
 * currently in its effective window. A workflow-specific delegation takes
 * precedence over a general ALL delegation when both exist.
 */
async function redirectThroughDelegation(db: D1Database, organisationId: string, userId: string, workflowId: string): Promise<string> {
  const now = new Date().toISOString();
  const delegation = await db.prepare(`SELECT delegate_user_id FROM workflow_delegations
    WHERE organisation_id=? AND delegator_user_id=? AND status='ACTIVE' AND effective_from<=? AND effective_to>=? AND (workflow_id IS NULL OR workflow_id=?)
    ORDER BY workflow_id IS NULL LIMIT 1`).bind(organisationId, userId, now, now, workflowId).first<{ delegate_user_id: string }>();
  return delegation?.delegate_user_id ?? userId;
}

/** Module 8 Phase C: resolves a workflow node's ROLE/USER/MANAGER assignee into a concrete user or role to assign the next task to. */
async function resolveAssignee(db: D1Database, organisation: OrganisationScope, initiatedBy: string, workflowId: string, assigneeType: string | null, assigneeReference: string | null): Promise<{ assignedUserId: string | null; assignedRoleId: string | null }> {
  if (assigneeType === "USER") {
    if (!assigneeReference) throw new ControlPlaneValidationError("ASSIGNEE_INVALID", "The workflow node has no assigned user reference.");
    const row = await db.prepare("SELECT id FROM app_users WHERE id=? AND status='ACTIVE'").bind(assigneeReference).first<{ id: string }>();
    if (!row) throw new ControlPlaneValidationError("ASSIGNEE_NOT_FOUND", "The workflow node's assigned user could not be found.");
    return { assignedUserId: await redirectThroughDelegation(db, organisation.id, assigneeReference, workflowId), assignedRoleId: null };
  }
  if (assigneeType === "ROLE") {
    if (!assigneeReference) throw new ControlPlaneValidationError("ASSIGNEE_INVALID", "The workflow node has no assigned role reference.");
    const row = await db.prepare("SELECT id FROM organisation_roles WHERE id=? AND organisation_id=? AND status='ACTIVE'").bind(assigneeReference, organisation.id).first<{ id: string }>();
    if (!row) throw new ControlPlaneValidationError("ASSIGNEE_NOT_FOUND", "The workflow node's assigned role could not be found.");
    return { assignedUserId: null, assignedRoleId: row.id };
  }
  const employee = await db.prepare("SELECT manager_employee_id FROM employees WHERE user_id=? AND organisation_id=?").bind(initiatedBy, organisation.id).first<{ manager_employee_id: string | null }>();
  if (!employee?.manager_employee_id) throw new RepositoryConflictError("The initiator has no manager on record to approve this workflow.");
  const manager = await db.prepare("SELECT user_id FROM employees WHERE id=? AND organisation_id=?").bind(employee.manager_employee_id, organisation.id).first<{ user_id: string | null }>();
  if (!manager?.user_id) throw new RepositoryConflictError("The initiator's manager has no linked user account.");
  return { assignedUserId: await redirectThroughDelegation(db, organisation.id, manager.user_id, workflowId), assignedRoleId: null };
}

function workflowOutboxStatement(db: D1Database, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string): D1PreparedStatement {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), "WORKFLOW_INSTANCE", aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

/**
 * Module 8 Phase C Assign: the previously entirely-missing command that
 * makes the Create/Publish/Decide pipeline reachable at all. Looks up the
 * organisation's current ACTIVE workflow for `domainAction` and its latest
 * PUBLISHED version, then advances from START using the same transition
 * resolution Decide and Test share. A path that reaches END immediately
 * (a workflow with no approval nodes at all — valid per
 * normalizeWorkflowDefinition, which requires only one START and one END)
 * completes the instance immediately with no assignment created.
 */
export async function assignWorkflow(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const assignment = normalizeWorkflowAssignment(input);
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "BUSINESS_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const workflow = await db.prepare("SELECT id FROM workflows WHERE organisation_id=? AND domain_action=? AND status='ACTIVE'").bind(organisation.id, assignment.domainAction).first<{ id: string }>();
  if (!workflow) throw new ControlPlaneValidationError("WORKFLOW_NOT_CONFIGURED", `No active workflow is configured for ${assignment.domainAction} in this organisation.`);
  const version = await db.prepare("SELECT id FROM workflow_versions WHERE workflow_id=? AND status='PUBLISHED' ORDER BY version_number DESC LIMIT 1").bind(workflow.id).first<{ id: string }>();
  if (!version) throw new ControlPlaneValidationError("WORKFLOW_NOT_CONFIGURED", `The ${assignment.domainAction} workflow has no published version.`);
  const startNode = await db.prepare("SELECT node_key FROM workflow_nodes WHERE workflow_version_id=? AND node_type='START'").bind(version.id).first<{ node_key: string }>();
  if (!startNode) throw new ControlPlaneValidationError("WORKFLOW_MALFORMED", "The published workflow version has no start node.");
  const next = await resolveNextNode(db, version.id, startNode.node_key, assignment.context);
  if (!next) throw new ControlPlaneValidationError("WORKFLOW_NO_MATCHING_PATH", "No workflow transition matches the supplied context.");
  const instanceId = crypto.randomUUID();
  const now = new Date().toISOString();
  if (next.nodeType === "END") {
    await db.batch([
      db.prepare("INSERT INTO workflow_instances VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        .bind(instanceId, organisation.id, version.id, assignment.resourceType, assignment.resourceId, actor.userId, "COMPLETED", next.nodeKey, JSON.stringify(assignment.context), now, now),
      workflowOutboxStatement(db, instanceId, "WorkflowInstanceCompleted", organisation.id, { instance_id: instanceId, domain_action: assignment.domainAction, resource_type: assignment.resourceType, resource_id: assignment.resourceId }, now),
      await appendAudit(db, actor, "WORKFLOW_INSTANCE_COMPLETED", "WORKFLOW_INSTANCE", instanceId, { organisationId: organisation.id, domainAction: assignment.domainAction }),
    ]);
    return { id: instanceId, status: "COMPLETED", currentNode: next.nodeKey, assignmentId: null };
  }
  const assignee = await resolveAssignee(db, organisation, actor.userId, workflow.id, next.assigneeType, next.assigneeReference);
  const assignmentId = crypto.randomUUID();
  await db.batch([
    db.prepare("INSERT INTO workflow_instances VALUES (?,?,?,?,?,?,?,?,?,?,?)")
      .bind(instanceId, organisation.id, version.id, assignment.resourceType, assignment.resourceId, actor.userId, "IN_PROGRESS", next.nodeKey, JSON.stringify(assignment.context), now, null),
    db.prepare("INSERT INTO workflow_assignments VALUES (?,?,?,?,?,?,?,?)")
      .bind(assignmentId, instanceId, next.nodeKey, assignee.assignedUserId, assignee.assignedRoleId, "PENDING", null, now),
    workflowOutboxStatement(db, instanceId, "WorkflowInstanceAssigned", organisation.id, { instance_id: instanceId, assignment_id: assignmentId, domain_action: assignment.domainAction, resource_type: assignment.resourceType, resource_id: assignment.resourceId, node_key: next.nodeKey }, now),
    await appendAudit(db, actor, "WORKFLOW_INSTANCE_ASSIGNED", "WORKFLOW_INSTANCE", instanceId, { organisationId: organisation.id, domainAction: assignment.domainAction, nodeKey: next.nodeKey }),
  ]);
  return { id: instanceId, status: "IN_PROGRESS", currentNode: next.nodeKey, assignmentId };
}

/**
 * Module 8 Phase C: now traverses the transition graph on APPROVE instead
 * of always completing the instance on the first decision — a real defect
 * discovered while wiring Assign, since a multi-APPROVAL-node workflow
 * (fully expressible via normalizeWorkflowDefinition since its Module 8
 * Phase C era) would otherwise have every node past the first silently
 * ignored. REJECT still terminates the whole instance immediately, the
 * same single-rejection-kills-the-chain rule this code already had. Also
 * now checks role-based assignment (assigned_role_id) before this phase
 * only assignedUserId was ever checked, so a role-assigned task — newly
 * possible now that Assign can create one — could otherwise be decided by
 * any actor holding workflows:decide, not just one holding the role.
 */
export async function decideWorkflowTask(actor: UserContext, assignmentId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "BUSINESS_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const decision = String(source.decision ?? "").toUpperCase();
  const reason = String(source.reason ?? "").trim();
  if (reason.length < 5 || reason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character decision reason.");
  const db = await ensureDatabase();
  const task = await db.prepare(`SELECT a.id,a.status,a.assigned_user_id,a.assigned_role_id,a.node_key,
      i.id AS instance_id,i.initiated_by,i.workflow_version_id,i.context_snapshot
    FROM workflow_assignments a JOIN workflow_instances i ON i.id=a.workflow_instance_id
    WHERE a.id=? AND i.organisation_id=?`).bind(assignmentId, organisation.id)
    .first<{ id: string; status: string; assigned_user_id: string | null; assigned_role_id: string | null; node_key: string; instance_id: string; initiated_by: string; workflow_version_id: string; context_snapshot: string }>();
  if (!task) throw new ControlPlaneValidationError("WORKFLOW_TASK_NOT_FOUND", "The workflow task is outside the active organisation scope.");
  if (task.status !== "PENDING") throw new RepositoryConflictError("The workflow task has already been decided.");
  if (task.assigned_role_id) {
    const holdsRole = await db.prepare("SELECT id FROM user_role_assignments WHERE user_id=? AND organisation_role_id=? AND organisation_id=? AND status='ACTIVE'")
      .bind(actor.userId, task.assigned_role_id, organisation.id).first<{ id: string }>();
    if (!holdsRole) throw new ControlPlaneValidationError("TASK_NOT_ASSIGNED", "You do not hold the role assigned to this workflow task.");
  }
  try {
    assertWorkflowDecision({ actorId: actor.userId, initiatedBy: task.initiated_by, assignedUserId: task.assigned_user_id, decision, emergencyOverride: source.emergency_override === true });
  } catch (error) {
    if (error instanceof ControlPlaneValidationError && ["SELF_APPROVAL_DENIED", "EMERGENCY_OVERRIDE_DISABLED"].includes(error.code)) {
      const rule = await db.prepare("SELECT id FROM sod_rules WHERE organisation_id=? AND code='NO_SELF_APPROVAL' AND status='ACTIVE'").bind(organisation.id).first<{ id: string }>();
      if (rule) {
        const violationId = crypto.randomUUID();
        const now = new Date().toISOString();
        await db.batch([
          db.prepare("INSERT INTO sod_violations VALUES (?,?,?,?,?,?,?,?,?,NULL)").bind(violationId, organisation.id, rule.id, actor.userId, "WORKFLOW_ASSIGNMENT", assignmentId, "OPEN", JSON.stringify({ code: error.code }), now),
          workflowOutboxStatement(db, task.instance_id, "SoDViolationDetected", organisation.id, { sod_violation_id: violationId, rule_code: "NO_SELF_APPROVAL", actor_id: actor.userId, resource_type: "WORKFLOW_ASSIGNMENT", resource_id: assignmentId }, now),
        ]);
      }
    }
    throw error;
  }
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [
    db.prepare("INSERT INTO workflow_approvals VALUES (?,?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), task.instance_id, task.id, task.workflow_version_id, actor.userId, decision, reason, JSON.stringify({ role: actor.role, permissions: actor.dynamicPermissions }), now),
    db.prepare("UPDATE workflow_assignments SET status=? WHERE id=? AND status='PENDING'").bind(decision === "APPROVE" ? "APPROVED" : "REJECTED", task.id),
  ];
  let instanceStatus: string;
  let nextAssignmentId: string | null = null;
  if (decision === "REJECT") {
    instanceStatus = "REJECTED";
    statements.push(db.prepare("UPDATE workflow_instances SET status='REJECTED',completed_at=? WHERE id=?").bind(now, task.instance_id));
  } else {
    const context = JSON.parse(task.context_snapshot) as Record<string, unknown>;
    const next = await resolveNextNode(db, task.workflow_version_id, task.node_key, context);
    if (!next || next.nodeType === "END") {
      instanceStatus = "COMPLETED";
      statements.push(
        db.prepare("UPDATE workflow_instances SET status='COMPLETED',completed_at=? WHERE id=?").bind(now, task.instance_id),
        workflowOutboxStatement(db, task.instance_id, "WorkflowInstanceCompleted", organisation.id, { instance_id: task.instance_id }, now),
      );
    } else {
      instanceStatus = "IN_PROGRESS";
      const workflowRow = await db.prepare("SELECT workflow_id FROM workflow_versions WHERE id=?").bind(task.workflow_version_id).first<{ workflow_id: string }>();
      const assignee = await resolveAssignee(db, organisation, task.initiated_by, workflowRow?.workflow_id ?? "", next.assigneeType, next.assigneeReference);
      nextAssignmentId = crypto.randomUUID();
      statements.push(
        db.prepare("INSERT INTO workflow_assignments VALUES (?,?,?,?,?,?,?,?)").bind(nextAssignmentId, task.instance_id, next.nodeKey, assignee.assignedUserId, assignee.assignedRoleId, "PENDING", null, now),
        db.prepare("UPDATE workflow_instances SET current_node_key=? WHERE id=?").bind(next.nodeKey, task.instance_id),
        workflowOutboxStatement(db, task.instance_id, "WorkflowInstanceAssigned", organisation.id, { instance_id: task.instance_id, assignment_id: nextAssignmentId, node_key: next.nodeKey }, now),
      );
    }
  }
  statements.push(await appendAudit(db, actor, `WORKFLOW_${decision}`, "WORKFLOW_ASSIGNMENT", task.id, { organisationId: organisation.id, reason, instanceStatus }));
  await db.batch(statements);
  return { id: task.id, decision, decidedAt: now, instanceStatus, nextAssignmentId };
}

/**
 * Module 8 Phase C Test: a pure dry-run — walks the same transition graph
 * Assign/Decide use, with a bounded hop count guarding against a
 * hand-crafted cyclical definition (normalizeWorkflowDefinition already
 * forbids a transition back to its own source node, but not a longer
 * cycle across several nodes). Works against a version in any status,
 * including DRAFT, since validating routing before publish is the point.
 */
export async function testWorkflowVersion(actor: UserContext, versionId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "READ", 0, requestedOrganisationId);
  const context = normalizeWorkflowTestContext(input);
  const db = await ensureDatabase();
  const version = await db.prepare("SELECT id FROM workflow_versions WHERE id=? AND organisation_id=?").bind(versionId, organisation.id).first<{ id: string }>();
  if (!version) throw new ControlPlaneValidationError("WORKFLOW_VERSION_NOT_FOUND", "The workflow version is outside the active organisation scope.");
  const startNode = await db.prepare("SELECT node_key,node_type,label,assignee_type,assignee_reference FROM workflow_nodes WHERE workflow_version_id=? AND node_type='START'")
    .bind(versionId).first<{ node_key: string; node_type: string; label: string; assignee_type: string | null; assignee_reference: string | null }>();
  if (!startNode) throw new ControlPlaneValidationError("WORKFLOW_MALFORMED", "The workflow version has no start node.");
  const path: NextNodeResolution[] = [{ nodeKey: startNode.node_key, nodeType: startNode.node_type, label: startNode.label, assigneeType: startNode.assignee_type, assigneeReference: startNode.assignee_reference }];
  let cursor = startNode.node_key;
  let terminal: "COMPLETED" | "NO_MATCHING_PATH" = "NO_MATCHING_PATH";
  for (let hop = 0; hop < 30; hop += 1) {
    const next = await resolveNextNode(db, versionId, cursor, context);
    if (!next) break;
    path.push(next);
    if (next.nodeType === "END") { terminal = "COMPLETED"; break; }
    cursor = next.nodeKey;
  }
  return { versionId, context, path, terminal };
}

/** Module 8 Phase C Delegate. */
export async function createDelegation(actor: UserContext, input: unknown, requestedOrganisationId?: string | null) {
  const delegation = normalizeDelegation(input);
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  for (const userId of [delegation.delegatorUserId, delegation.delegateUserId]) {
    const row = await db.prepare("SELECT id FROM app_users WHERE id=? AND status='ACTIVE'").bind(userId).first<{ id: string }>();
    if (!row) throw new ControlPlaneValidationError("DELEGATION_USER_NOT_FOUND", "The delegator or delegate account could not be found.");
  }
  if (delegation.workflowId) {
    const workflow = await db.prepare("SELECT id FROM workflows WHERE id=? AND organisation_id=?").bind(delegation.workflowId, organisation.id).first<{ id: string }>();
    if (!workflow) throw new ControlPlaneValidationError("WORKFLOW_NOT_FOUND", "The referenced workflow is outside the active organisation scope.");
  }
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare("INSERT INTO workflow_delegations (id,organisation_id,delegator_user_id,delegate_user_id,workflow_id,scope,status,effective_from,effective_to,approved_by,reason,revoked_reason) VALUES (?,?,?,?,?,?,'ACTIVE',?,?,?,?,NULL)")
      .bind(id, organisation.id, delegation.delegatorUserId, delegation.delegateUserId, delegation.workflowId, delegation.scope, delegation.effectiveFrom, delegation.effectiveTo, actor.userId, delegation.reason),
    await appendAudit(db, actor, "WORKFLOW_DELEGATION_CREATED", "WORKFLOW_DELEGATION", id, { organisationId: organisation.id, delegatorUserId: delegation.delegatorUserId, delegateUserId: delegation.delegateUserId, reason: delegation.reason }),
  ]);
  return { id, status: "ACTIVE", delegatorUserId: delegation.delegatorUserId, delegateUserId: delegation.delegateUserId, workflowId: delegation.workflowId, scope: delegation.scope, effectiveFrom: delegation.effectiveFrom, effectiveTo: delegation.effectiveTo };
}

export async function listDelegations(actor: UserContext, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "READ", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const rows = await db.prepare(`SELECT d.id,d.delegator_user_id,delegator.display_name AS delegator_name,d.delegate_user_id,delegate.display_name AS delegate_name,
      d.workflow_id,w.name AS workflow_name,d.scope,d.status,d.effective_from,d.effective_to,d.reason
    FROM workflow_delegations d JOIN app_users delegator ON delegator.id=d.delegator_user_id JOIN app_users delegate ON delegate.id=d.delegate_user_id
    LEFT JOIN workflows w ON w.id=d.workflow_id WHERE d.organisation_id=? ORDER BY d.effective_from DESC`).bind(organisation.id).all<Record<string, string | null>>();
  return rows.results;
}

/** Module 8 Phase C RevokeDelegation. */
export async function revokeDelegation(actor: UserContext, delegationId: string, input: unknown, requestedOrganisationId?: string | null) {
  const { organisation } = await assertEntitledOperation(actor, "ADVANCED_WORKFLOW", "ADMIN_WRITE", 0, requestedOrganisationId);
  const source = input && typeof input === "object" && !Array.isArray(input) ? input as Record<string, unknown> : {};
  const reason = String(source.reason ?? "").trim().replace(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) throw new ControlPlaneValidationError("REASON_REQUIRED", "Provide a 5 to 240 character revocation reason.");
  const db = await ensureDatabase();
  const row = await db.prepare("SELECT id,status FROM workflow_delegations WHERE id=? AND organisation_id=?").bind(delegationId, organisation.id).first<{ id: string; status: string }>();
  if (!row) throw new ControlPlaneValidationError("DELEGATION_NOT_FOUND", "The delegation is outside the active organisation scope.");
  if (row.status !== "ACTIVE") throw new RepositoryConflictError("Only an active delegation can be revoked.");
  await db.batch([
    db.prepare("UPDATE workflow_delegations SET status='REVOKED',revoked_reason=? WHERE id=? AND status='ACTIVE'").bind(reason, delegationId),
    await appendAudit(db, actor, "WORKFLOW_DELEGATION_REVOKED", "WORKFLOW_DELEGATION", delegationId, { organisationId: organisation.id, reason }),
  ]);
  return { id: delegationId, status: "REVOKED" };
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

export type AccessGrantRevocationResult = { id: string; grantType: "ROLE" | "CAPABILITY"; userId: string; status: string };

/**
 * Access Governance RevokeAccess: revokes a single active role or capability
 * grant on demand. Previously the only ways to walk back an already-granted
 * role/capability were the bulk "revoke everything" paths inside
 * certifyQuarterlyAccess (gated behind an open review reaching full
 * completion across every active member) and terminateEmployee (a one-way
 * offboarding that also ends the employment record and a licence seat) —
 * there was no way to revoke just one grant without either.
 */
export async function revokeAccessGrant(
  actor: UserContext,
  input: unknown,
  requestedOrganisationId?: string | null,
): Promise<AccessGrantRevocationResult> {
  const revocation = normalizeAccessRevocation(input);
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 0, requestedOrganisationId);
  const db = await ensureDatabase();
  const table = revocation.grantType === "ROLE" ? "user_role_assignments" : "user_capability_assignments";
  const resourceType = revocation.grantType === "ROLE" ? "USER_ROLE_ASSIGNMENT" : "USER_CAPABILITY_ASSIGNMENT";

  const grant = await db.prepare(`SELECT id,user_id,status FROM ${table} WHERE id=? AND organisation_id=?`)
    .bind(revocation.grantId, organisation.id).first<{ id: string; user_id: string; status: string }>();
  if (!grant) throw new ControlPlaneValidationError("GRANT_NOT_FOUND", "The access grant is outside the active organisation.");
  if (actor.userId === grant.user_id) throw new ControlPlaneValidationError("SELF_REVOCATION_DENIED", "You cannot revoke your own access grant.");
  if (grant.status !== "ACTIVE") return { id: grant.id, grantType: revocation.grantType, userId: grant.user_id, status: grant.status };

  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`UPDATE ${table} SET status='REVOKED',effective_to=? WHERE id=?`).bind(now, grant.id),
    await appendAudit(db, actor, "ACCESS_REVOKED", resourceType, grant.id, { organisationId: organisation.id, userId: grant.user_id, grantType: revocation.grantType, reason: revocation.reason }),
  ]);
  return { id: grant.id, grantType: revocation.grantType, userId: grant.user_id, status: "REVOKED" };
}

export type OffboardingResult = {
  userId: string;
  organisationId: string;
  membershipRevoked: boolean;
  roleAssignmentsRevoked: number;
  capabilityAssignmentsRevoked: number;
};

/**
 * Access Governance Offboard: revokes every active role/capability grant and
 * the organisation membership itself for one user, immediately — the
 * access-only counterpart to terminateEmployee (which also ends the
 * employment record and a licence seat) and to certifyQuarterlyAccess's
 * REVOKE disposition (gated behind an open review reaching full completion
 * across every active member). For a security incident or an access-only
 * exit where the employment/licence side should stay untouched. Idempotent.
 */
export async function offboardUser(
  actor: UserContext,
  input: unknown,
  requestedOrganisationId?: string | null,
): Promise<OffboardingResult> {
  const offboarding = normalizeOffboarding(input);
  const { organisation } = await assertEntitledOperation(actor, "ADMINISTRATION", "ADMIN_WRITE", 0, requestedOrganisationId);
  if (actor.userId === offboarding.userId) throw new ControlPlaneValidationError("SELF_OFFBOARD_DENIED", "You cannot offboard your own access.");
  const db = await ensureDatabase();

  const [membership, roleCount, capabilityCount] = await Promise.all([
    db.prepare("SELECT id FROM organisation_memberships WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, offboarding.userId).first<{ id: string }>(),
    db.prepare("SELECT COUNT(*) AS n FROM user_role_assignments WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, offboarding.userId).first<{ n: number }>(),
    db.prepare("SELECT COUNT(*) AS n FROM user_capability_assignments WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(organisation.id, offboarding.userId).first<{ n: number }>(),
  ]);
  const roleAssignmentsRevoked = roleCount?.n ?? 0;
  const capabilityAssignmentsRevoked = capabilityCount?.n ?? 0;
  if (!membership && !roleAssignmentsRevoked && !capabilityAssignmentsRevoked) {
    return { userId: offboarding.userId, organisationId: organisation.id, membershipRevoked: false, roleAssignmentsRevoked: 0, capabilityAssignmentsRevoked: 0 };
  }

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE organisation_memberships SET status='REVOKED',valid_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, offboarding.userId),
    db.prepare("UPDATE user_role_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, offboarding.userId),
    db.prepare("UPDATE user_capability_assignments SET status='REVOKED',effective_to=? WHERE organisation_id=? AND user_id=? AND status='ACTIVE'").bind(now, organisation.id, offboarding.userId),
    await appendAudit(db, actor, "USER_OFFBOARDED", "ORGANISATION_MEMBERSHIP", offboarding.userId, {
      organisationId: organisation.id, reason: offboarding.reason, roleAssignmentsRevoked, capabilityAssignmentsRevoked,
    }),
  ]);
  return { userId: offboarding.userId, organisationId: organisation.id, membershipRevoked: Boolean(membership), roleAssignmentsRevoked, capabilityAssignmentsRevoked };
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
