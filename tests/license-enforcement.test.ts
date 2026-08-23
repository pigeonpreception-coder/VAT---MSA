import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function applyMigration(db: DatabaseSync, fileName: string) {
  const sql = readFileSync(join(process.cwd(), "drizzle", fileName), "utf8");
  for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
}

function filesBelow(root: string, name: string): string[] {
  return readdirSync(root).flatMap((entry) => {
    const path = join(root, entry);
    return statSync(path).isDirectory() ? filesBelow(path, name) : entry === name ? [path] : [];
  });
}

describe("central licence enforcement migration", () => {
  it("registers every granted permission and classifies continuity-sensitive navigation", () => {
    const db = new DatabaseSync(":memory:");
    const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
    const centralMigration = "0012_central_license_enforcement.sql";
    const centralIndex = migrations.indexOf(centralMigration);
    expect(centralIndex).toBeGreaterThan(0);
    for (const migration of migrations.slice(0, centralIndex)) applyMigration(db, migration);
    db.exec("PRAGMA foreign_keys=ON");
    const authSource = readFileSync(join(process.cwd(), "lib", "auth.ts"), "utf8");
    const grantedPermissions = new Set([...authSource.matchAll(/"([a-z][a-z0-9-]*:[a-z][a-z0-9-]*)"/g)].map((match) => match[1]));
    for (const permission of grantedPermissions) {
      db.prepare("INSERT OR IGNORE INTO access_permissions VALUES (?,?,?,?,?,?)")
        .run(permission, "TEST_RESOURCE", "TEST_ACTION", "Synthetic policy coverage fixture", "RESTRICTED", "2026-08-23T08:00:00Z");
    }
    for (const feature of ["CORE_VAT", "ADMINISTRATION", "USER_SEATS", "ADVANCED_WORKFLOW", "ACCOUNTING", "INVENTORY", "PROJECTS", "ANALYTICS", "API_ACCESS"]) {
      db.prepare("INSERT INTO license_features VALUES (?,?,?,?,?,?)")
        .run(feature, feature, "Synthetic policy coverage fixture", null, 0, "2026-08-23T08:00:00Z");
    }
    db.prepare("INSERT INTO navigation_workspaces VALUES ('workspace','workspace','Workspace','Synthetic fixture',1,'ACTIVE','INTERNAL')").run();
    db.prepare("INSERT INTO navigation_folders VALUES ('folder','workspace',NULL,'folder','Folder',1,'ACTIVE')").run();
    db.prepare(`INSERT INTO navigation_items
      (id,workspace_id,folder_id,item_key,label,href,feature_key,capability,required_permission,sort_order,status,classification)
      VALUES ('nitem-dashboard','workspace','folder','dashboard','Dashboard','/','CORE_VAT',NULL,'dashboard:read',1,'ACTIVE','INTERNAL'),
             ('nitem-new-invoice','workspace','folder','new-invoice','New invoice','/invoices/new','CORE_VAT',NULL,'invoices:submit',2,'ACTIVE','RESTRICTED')`).run();
    applyMigration(db, centralMigration);

    expect(db.prepare("SELECT feature_key,operation_class FROM license_permission_policies WHERE permission_code='reports:run'").get())
      .toEqual({ feature_key: "ANALYTICS", operation_class: "EXPORT" });
    expect(db.prepare("SELECT operation_class FROM license_permission_policies WHERE permission_code='returns:submit'").get())
      .toEqual({ operation_class: "COMPLIANCE_WRITE" });
    expect(db.prepare("SELECT operation_class FROM license_permission_policies WHERE permission_code='invoices:submit'").get())
      .toEqual({ operation_class: "BUSINESS_WRITE" });
    expect(db.prepare("SELECT navigation_item_id,operation_class FROM license_navigation_policies ORDER BY navigation_item_id").all())
      .toEqual([
        { navigation_item_id: "nitem-dashboard", operation_class: "READ" },
        { navigation_item_id: "nitem-new-invoice", operation_class: "BUSINESS_WRITE" },
      ]);
    expect(() => db.prepare("UPDATE license_permission_policies SET operation_class='UNCONTROLLED' WHERE permission_code='dashboard:read'").run())
      .toThrow(/CHECK constraint/i);

    const registered = new Set((db.prepare("SELECT permission_code FROM license_permission_policies").all() as Array<{ permission_code: string }>).map((row) => row.permission_code));
    expect([...grantedPermissions].filter((permission) => !registered.has(permission))).toEqual([]);
    db.close();
  });
});

describe("licence enforcement coverage", () => {
  it("routes every protected page and API through the central guard", () => {
    const pages = filesBelow(join(process.cwd(), "app"), "page.tsx");
    const publicPages = new Set([
      join("app", "signup", "page.tsx"),
      join("app", "verify", "[token]", "page.tsx"),
    ]);
    const uncoveredPages = pages.filter((path) => {
      const local = relative(process.cwd(), path);
      if (publicPages.has(local)) return false;
      const source = readFileSync(path, "utf8");
      return !/requireLicensedPermission|<AppShell|<PortalShell/.test(source);
    }).map((path) => relative(process.cwd(), path));
    expect(uncoveredPages).toEqual([]);

    const routes = filesBelow(join(process.cwd(), "app", "api"), "route.ts");
    const publicRoutes = new Set([
      join("app", "api", "health", "live", "route.ts"),
      join("app", "api", "health", "ready", "route.ts"),
      join("app", "api", "v1", "signup-applications", "route.ts"),
      join("app", "api", "v1", "verify", "[token]", "route.ts"),
    ]);
    const sharedGuards = /requireLicensedPermission|handleBusiness(Get|Post)|handleCompliance(List|Command)|handleVat(LifecycleList|ReturnDetail|Command)|handle(PlatformList|OfflineBatch|ReportRun|DocumentUpload)/;
    const uncoveredRoutes = routes.filter((path) => {
      const local = relative(process.cwd(), path);
      return !publicRoutes.has(local) && !sharedGuards.test(readFileSync(path, "utf8"));
    }).map((path) => relative(process.cwd(), path));
    expect(uncoveredRoutes).toEqual([]);
  });
});
