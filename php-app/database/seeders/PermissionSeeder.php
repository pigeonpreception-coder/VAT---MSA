<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ported from db/runtime.ts's access_permissions seed rows (SECURITY_SEED_STATEMENTS,
 * IDENTITY/BUSINESS/COMPLIANCE/PLATFORM/CONTROL_PLANE_SEED_STATEMENTS) --
 * (code, resource, action, description, classification) copied verbatim.
 *
 * NOTE -- a second genuine source gap, same shape as RoleSeeder's: 12
 * permission codes are granted by lib/domain/access.ts's ROLE_PERMISSIONS
 * (taxpayers:suspend, registrations:approve, invoices:cancel, vat-rules:read,
 * vat-rules:manage, cases:override-sod, obligations:manage, payments:record,
 * security:manage, accounting:close-period, documents:manage, exceptions:read)
 * but were never seeded into access_permissions in the source either.
 * Completed below, marked distinctly, with reasonable resource/action/
 * classification values following the seeded rows' own pattern -- not
 * verified source data. `exceptions:read` was found by Phase 12's portal-
 * navigation slice, not the original 11-code audit: navigation_items.
 * required_permission also references it (nitem-reconciliation), and it is
 * the first place in this whole migration where a ROLE_PERMISSIONS-only
 * code actually has to resolve against the real access_permissions
 * catalogue (an organisation-defined custom role's permission FK) --
 * previously it only ever flowed through the unconstrained static role map.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // [code, resource, action, description, classification]
        $permissions = [
            ['identity:read', 'IDENTITY', 'READ', 'Read the identity foundation posture', 'RESTRICTED'],
            ['taxpayers:read', 'TAXPAYER', 'READ', 'Read authorised taxpayer records', 'RESTRICTED'],
            ['registrations:read', 'REGISTRATION', 'READ', 'Read authorised registration applications', 'RESTRICTED'],
            ['registrations:submit', 'REGISTRATION', 'SUBMIT', 'Submit a registration application', 'RESTRICTED'],
            ['organisations:manage', 'ORGANISATION', 'MANAGE', 'Manage organisation membership and branches', 'RESTRICTED'],
            ['invoices:read', 'INVOICE', 'READ', 'Read authorised invoices', 'RESTRICTED'],
            ['invoices:submit', 'INVOICE', 'SUBMIT', 'Submit invoices for certification', 'RESTRICTED'],
            ['returns:read', 'VAT_RETURN', 'READ', 'Read authorised VAT returns', 'RESTRICTED'],
            ['audit:read', 'AUDIT', 'READ', 'Read authorised audit evidence', 'RESTRICTED'],
            ['security:read', 'SECURITY', 'READ', 'Read security operations posture', 'SECURITY'],
            ['commercial:read', 'COMMERCIAL', 'READ', 'Read customer, supplier, product and quotation records', 'RESTRICTED'],
            ['parties:manage', 'BUSINESS_PARTY', 'MANAGE', 'Create update and non-destructively deactivate customer and supplier records', 'CONFIDENTIAL'],
            ['quotations:manage', 'QUOTATION', 'MANAGE', 'Create and transition authorised quotations', 'RESTRICTED'],
            ['accounting:read', 'ACCOUNTING', 'READ', 'Read the authorised chart and journals', 'CONFIDENTIAL'],
            ['accounting:post', 'ACCOUNTING', 'POST', 'Post balanced journals', 'CONFIDENTIAL'],
            ['expenses:read', 'EXPENSE', 'READ', 'Read authorised expenses', 'CONFIDENTIAL'],
            ['expenses:manage', 'EXPENSE', 'MANAGE', 'Record and transition authorised expenses', 'CONFIDENTIAL'],
            ['inventory:read', 'INVENTORY', 'READ', 'Read authorised inventory', 'RESTRICTED'],
            ['inventory:manage', 'INVENTORY', 'MANAGE', 'Record authorised stock movements', 'RESTRICTED'],
            ['projects:read', 'PROJECT', 'READ', 'Read authorised projects', 'RESTRICTED'],
            ['projects:manage', 'PROJECT', 'MANAGE', 'Create and manage authorised projects', 'RESTRICTED'],
            ['imports:read', 'IMPORT', 'READ', 'Read authorised import declarations', 'CONFIDENTIAL'],
            ['imports:manage', 'IMPORT', 'MANAGE', 'Record authorised import declarations', 'CONFIDENTIAL'],
            ['documents:read', 'DOCUMENT', 'READ', 'Read authorised document metadata', 'CONFIDENTIAL'],
            ['documents:upload', 'DOCUMENT', 'UPLOAD', 'Upload governed evidence into quarantine', 'CONFIDENTIAL'],
            ['returns:generate', 'VAT_RETURN', 'GENERATE', 'Generate a reproducible return version from controlled ledger evidence', 'CONFIDENTIAL'],
            ['returns:approve', 'VAT_RETURN', 'APPROVE', 'Approve a return version subject to maker-checker separation', 'CONFIDENTIAL'],
            ['returns:submit', 'VAT_RETURN', 'SUBMIT', 'Request submission of an approved return to the statutory provider', 'CONFIDENTIAL'],
            ['vat-adjustments:manage', 'VAT_ADJUSTMENT', 'MANAGE', 'Submit governed VAT adjustments for independent approval', 'CONFIDENTIAL'],
            ['reconciliation:manage', 'RECONCILIATION', 'MANAGE', 'Review and resolve controlled reconciliation evidence', 'CONFIDENTIAL'],
            ['compliance:read', 'COMPLIANCE', 'READ', 'Read authorised obligations, communications and compliance posture', 'CONFIDENTIAL'],
            ['cases:manage', 'AUDIT_CASE', 'MANAGE', 'Open and manage controlled compliance cases', 'CONFIDENTIAL'],
            ['disputes:manage', 'DISPUTE', 'MANAGE', 'File and manage taxpayer disputes', 'CONFIDENTIAL'],
            ['refunds:read', 'REFUND', 'READ', 'Read authorised refund workflow records', 'CONFIDENTIAL'],
            ['refunds:request', 'REFUND', 'REQUEST', 'Request refund eligibility review', 'CONFIDENTIAL'],
            ['refunds:review', 'REFUND', 'REVIEW', 'Perform staged refund review', 'CONFIDENTIAL'],
            ['risk:read', 'RISK', 'READ', 'Read explainable advisory risk indicators', 'CONFIDENTIAL'],
            ['risk:review', 'RISK', 'REVIEW', 'Review advisory risk indicators without automated adverse action', 'CONFIDENTIAL'],
            ['communications:manage', 'COMMUNICATION', 'MANAGE', 'Record controlled taxpayer communications', 'CONFIDENTIAL'],
            ['communications:respond', 'COMMUNICATION', 'RESPOND', 'Respond within an existing NamRA correspondence thread', 'CONFIDENTIAL'],
            ['notifications:manage', 'NOTIFICATION', 'MANAGE', 'Queue a notification directly', 'CONFIDENTIAL'],
            ['reports:executive', 'REPORT', 'EXECUTIVE', 'Run executive-tier aggregate reports', 'CONFIDENTIAL'],
            ['consents:manage', 'CONSENT', 'MANAGE', 'Manage taxpayer consents and delegations', 'CONFIDENTIAL'],
            ['integrations:read', 'INTEGRATION', 'READ', 'Read integration capability and health posture', 'RESTRICTED'],
            ['integrations:manage', 'INTEGRATION', 'MANAGE', 'Manage governed integration configuration references', 'RESTRICTED'],
            ['developer:read', 'DEVELOPER', 'READ', 'Read API client and webhook posture', 'RESTRICTED'],
            ['developer:manage', 'DEVELOPER', 'MANAGE', 'Manage API clients without exposing credentials', 'RESTRICTED'],
            ['offline:read', 'OFFLINE', 'READ', 'Read offline device, range, batch and conflict posture', 'RESTRICTED'],
            ['offline:sync', 'OFFLINE', 'SYNC', 'Submit signed ordered offline batches', 'RESTRICTED'],
            ['reports:read', 'REPORT', 'READ', 'Read governed report definitions and runs', 'CONFIDENTIAL'],
            ['reports:run', 'REPORT', 'RUN', 'Run tenant-scoped governed reports', 'CONFIDENTIAL'],
            ['platform:read', 'PLATFORM', 'READ', 'Read service component and queue posture', 'SECURITY'],
            ['payments:read', 'PAYMENT', 'READ', 'Read governed payment instruction posture', 'CONFIDENTIAL'],
            ['administration:read', 'ADMINISTRATION', 'READ', 'Read authorised access administration posture', 'RESTRICTED'],
            ['administration:manage', 'ADMINISTRATION', 'MANAGE', 'Manage governed identity and access administration', 'SECURITY'],
            ['platform:manage', 'PLATFORM', 'MANAGE', 'Manage governed technical platform configuration references', 'SECURITY'],
            ['workspace:read', 'WORKSPACE', 'READ', 'Read the effective hierarchical workspace', 'RESTRICTED'],
            ['search:read', 'SEARCH', 'READ', 'Use permission-filtered workspace and record search', 'RESTRICTED'],
            ['licensing:read', 'LICENSING', 'READ', 'Read organisation licence entitlements and usage', 'COMMERCIAL'],
            ['licensing:request', 'LICENSING', 'REQUEST', 'Request an approved licence change without changing state', 'COMMERCIAL'],
            ['employees:read', 'EMPLOYEE', 'READ', 'Read authorised organisation employees', 'CONFIDENTIAL'],
            ['employees:manage', 'EMPLOYEE', 'MANAGE', 'Invite suspend and offboard authorised employees', 'RESTRICTED'],
            ['roles:read', 'ORGANISATION_ROLE', 'READ', 'Read organisation-specific roles', 'RESTRICTED'],
            ['roles:manage', 'ORGANISATION_ROLE', 'MANAGE', 'Create roles from the protected permission catalogue', 'SECURITY'],
            ['workflows:read', 'WORKFLOW', 'READ', 'Read versioned organisation workflows', 'RESTRICTED'],
            ['workflows:manage', 'WORKFLOW', 'MANAGE', 'Create test and request publication of typed workflows', 'SECURITY'],
            ['workflows:decide', 'WORKFLOW', 'DECIDE', 'Decide only assigned workflow tasks under segregation of duties', 'RESTRICTED'],
            ['access-governance:read', 'ACCESS_GOVERNANCE', 'READ', 'Read access requests reviews and certifications', 'RESTRICTED'],
            ['access-governance:manage', 'ACCESS_GOVERNANCE', 'MANAGE', 'Decide access requests and certify or revoke access', 'SECURITY'],

            // Completed here (see class doc comment) -- granted by ROLE_PERMISSIONS but never
            // seeded into access_permissions in the source.
            ['taxpayers:suspend', 'TAXPAYER', 'SUSPEND', 'Suspend a taxpayer\'s VAT status', 'RESTRICTED'],
            ['registrations:approve', 'REGISTRATION', 'APPROVE', 'Approve a registration application', 'RESTRICTED'],
            ['invoices:cancel', 'INVOICE', 'CANCEL', 'Cancel a certified invoice under narrow eligibility', 'RESTRICTED'],
            ['vat-rules:read', 'VAT_RULE', 'READ', 'Read authority-approved VAT rules', 'RESTRICTED'],
            ['vat-rules:manage', 'VAT_RULE', 'MANAGE', 'Propose and approve VAT rules', 'CONFIDENTIAL'],
            ['cases:override-sod', 'AUDIT_CASE', 'OVERRIDE_SOD', 'Override segregation-of-duties on a compliance case', 'SECURITY'],
            ['obligations:manage', 'OBLIGATION', 'MANAGE', 'Create and mark statutory obligations satisfied', 'CONFIDENTIAL'],
            ['payments:record', 'PAYMENT', 'RECORD', 'Record a governed payment instruction outcome', 'CONFIDENTIAL'],
            ['security:manage', 'SECURITY', 'MANAGE', 'Manage security incidents and detection posture', 'SECURITY'],
            ['accounting:close-period', 'ACCOUNTING', 'CLOSE_PERIOD', 'Close an accounting period and block further postings', 'CONFIDENTIAL'],
            ['documents:manage', 'DOCUMENT', 'MANAGE', 'Record scan verdicts and manage retention holds on documents', 'CONFIDENTIAL'],
            ['exceptions:read', 'RECONCILIATION_EXCEPTION', 'READ', 'Read authorised VAT reconciliation exceptions', 'RESTRICTED'],
        ];

        foreach ($permissions as [$code, $resource, $action, $description, $classification]) {
            DB::table('access_permissions')->updateOrInsert(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $description, 'classification' => $classification, 'created_at' => $now],
            );
        }
    }
}
