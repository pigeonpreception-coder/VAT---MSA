CREATE TABLE `access_approvals` (
	`id` text PRIMARY KEY NOT NULL,
	`access_request_id` text NOT NULL,
	`reviewer_id` text NOT NULL,
	`reviewer_stage` text NOT NULL,
	`decision` text NOT NULL,
	`reason` text NOT NULL,
	`decided_at` text NOT NULL,
	FOREIGN KEY (`access_request_id`) REFERENCES `access_requests`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewer_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE TABLE `access_certifications` (
	`id` text PRIMARY KEY NOT NULL,
	`access_review_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`subject_user_id` text NOT NULL,
	`reviewer_id` text NOT NULL,
	`snapshot` text NOT NULL,
	`disposition` text NOT NULL,
	`finding` text,
	`certified_at` text NOT NULL,
	FOREIGN KEY (`access_review_id`) REFERENCES `access_reviews`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`subject_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewer_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_access_certification_subject` ON `access_certifications` (`access_review_id`,`subject_user_id`);--> statement-breakpoint
CREATE TABLE `access_requests` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`requested_by` text NOT NULL,
	`subject_user_id` text NOT NULL,
	`organisation_role_id` text NOT NULL,
	`justification` text NOT NULL,
	`status` text NOT NULL,
	`requested_at` text NOT NULL,
	`completed_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`subject_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_role_id`) REFERENCES `organisation_roles`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_access_requests_org_status` ON `access_requests` (`organisation_id`,`status`,`requested_at`);--> statement-breakpoint
CREATE TABLE `access_reviews` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`name` text NOT NULL,
	`review_type` text NOT NULL,
	`status` text NOT NULL,
	`period_start` text NOT NULL,
	`due_at` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`completed_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_access_reviews_org_status` ON `access_reviews` (`organisation_id`,`status`,`due_at`);--> statement-breakpoint
CREATE TABLE `business_units` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_business_units_org_code` ON `business_units` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `departments` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`parent_department_id` text,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_departments_org_code` ON `departments` (`organisation_id`,`code`);--> statement-breakpoint
CREATE INDEX `idx_departments_org_status` ON `departments` (`organisation_id`,`status`);--> statement-breakpoint
CREATE TABLE `employees` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`user_id` text,
	`employee_number` text NOT NULL,
	`full_name` text NOT NULL,
	`email` text NOT NULL,
	`position_id` text,
	`job_title_id` text,
	`department_id` text,
	`business_unit_id` text,
	`branch_id` text,
	`manager_employee_id` text,
	`status` text NOT NULL,
	`invited_at` text,
	`activated_at` text,
	`terminated_at` text,
	`last_activity_at` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`position_id`) REFERENCES `positions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`job_title_id`) REFERENCES `job_titles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`business_unit_id`) REFERENCES `business_units`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_employees_org_number` ON `employees` (`organisation_id`,`employee_number`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_employees_org_email` ON `employees` (`organisation_id`,`email`);--> statement-breakpoint
CREATE INDEX `idx_employees_org_status_name` ON `employees` (`organisation_id`,`status`,`full_name`);--> statement-breakpoint
CREATE TABLE `job_titles` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`description` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_job_titles_org_code` ON `job_titles` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `license_events` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_license_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`event_type` text NOT NULL,
	`from_state` text,
	`to_state` text NOT NULL,
	`authority` text NOT NULL,
	`reason` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`organisation_license_id`) REFERENCES `organisation_licenses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_license_events_org_time` ON `license_events` (`organisation_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `license_features` (
	`feature_key` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`description` text NOT NULL,
	`metric_key` text,
	`protected` integer DEFAULT 0 NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `license_plan_entitlements` (
	`id` text PRIMARY KEY NOT NULL,
	`license_plan_id` text NOT NULL,
	`feature_key` text NOT NULL,
	`enabled` integer DEFAULT 1 NOT NULL,
	`limit_value` integer,
	`configuration` text DEFAULT '{}' NOT NULL,
	FOREIGN KEY (`license_plan_id`) REFERENCES `license_plans`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`feature_key`) REFERENCES `license_features`(`feature_key`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_plan_entitlement_feature` ON `license_plan_entitlements` (`license_plan_id`,`feature_key`);--> statement-breakpoint
CREATE TABLE `license_plans` (
	`id` text PRIMARY KEY NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`version` integer NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_license_plan_code_version` ON `license_plans` (`code`,`version`);--> statement-breakpoint
CREATE TABLE `license_usage` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_license_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`metric_key` text NOT NULL,
	`period_key` text NOT NULL,
	`used_value` integer DEFAULT 0 NOT NULL,
	`reserved_value` integer DEFAULT 0 NOT NULL,
	`version` integer DEFAULT 0 NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_license_id`) REFERENCES `organisation_licenses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_license_usage_metric_period` ON `license_usage` (`organisation_id`,`metric_key`,`period_key`);--> statement-breakpoint
CREATE TABLE `navigation_folders` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`parent_folder_id` text,
	`folder_key` text NOT NULL,
	`label` text NOT NULL,
	`sort_order` integer NOT NULL,
	`status` text NOT NULL,
	FOREIGN KEY (`workspace_id`) REFERENCES `navigation_workspaces`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_navigation_folder_key` ON `navigation_folders` (`workspace_id`,`folder_key`);--> statement-breakpoint
CREATE INDEX `idx_navigation_folders_parent` ON `navigation_folders` (`workspace_id`,`parent_folder_id`,`sort_order`);--> statement-breakpoint
CREATE TABLE `navigation_items` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_id` text NOT NULL,
	`folder_id` text NOT NULL,
	`item_key` text NOT NULL,
	`label` text NOT NULL,
	`href` text NOT NULL,
	`feature_key` text,
	`capability` text,
	`required_permission` text NOT NULL,
	`sort_order` integer NOT NULL,
	`status` text NOT NULL,
	`classification` text NOT NULL,
	FOREIGN KEY (`workspace_id`) REFERENCES `navigation_workspaces`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`folder_id`) REFERENCES `navigation_folders`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_navigation_item_key` ON `navigation_items` (`item_key`);--> statement-breakpoint
CREATE INDEX `idx_navigation_items_folder` ON `navigation_items` (`folder_id`,`sort_order`);--> statement-breakpoint
CREATE TABLE `navigation_permissions` (
	`id` text PRIMARY KEY NOT NULL,
	`navigation_item_id` text NOT NULL,
	`policy_key` text NOT NULL,
	`effect` text NOT NULL,
	`safe_restriction_reason` text NOT NULL,
	FOREIGN KEY (`navigation_item_id`) REFERENCES `navigation_items`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE TABLE `navigation_preferences` (
	`id` text PRIMARY KEY NOT NULL,
	`user_id` text NOT NULL,
	`organisation_id` text,
	`preference_type` text NOT NULL,
	`value` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_navigation_preference` ON `navigation_preferences` (`user_id`,`organisation_id`,`preference_type`);--> statement-breakpoint
CREATE TABLE `navigation_workspaces` (
	`id` text PRIMARY KEY NOT NULL,
	`workspace_key` text NOT NULL,
	`label` text NOT NULL,
	`description` text NOT NULL,
	`sort_order` integer NOT NULL,
	`status` text NOT NULL,
	`classification` text NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_navigation_workspace_key` ON `navigation_workspaces` (`workspace_key`);--> statement-breakpoint
CREATE TABLE `organisation_administrator_roles` (
	`code` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`maximum_scope` text NOT NULL,
	`protected` integer DEFAULT 1 NOT NULL
);
--> statement-breakpoint
CREATE TABLE `organisation_administrators` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`user_id` text NOT NULL,
	`employee_id` text,
	`administrator_role_code` text NOT NULL,
	`scope` text NOT NULL,
	`is_primary` integer DEFAULT 0 NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`appointed_by` text NOT NULL,
	`approval_reference` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`administrator_role_code`) REFERENCES `organisation_administrator_roles`(`code`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_org_admins_org_status` ON `organisation_administrators` (`organisation_id`,`status`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_org_admin_user_role` ON `organisation_administrators` (`organisation_id`,`user_id`,`administrator_role_code`);--> statement-breakpoint
CREATE TABLE `organisation_licenses` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`subscription_id` text NOT NULL,
	`license_plan_id` text NOT NULL,
	`state` text NOT NULL,
	`state_version` integer DEFAULT 1 NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`grace_ends_at` text,
	`retention_policy` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`license_plan_id`) REFERENCES `license_plans`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_organisation_license_effective` ON `organisation_licenses` (`organisation_id`,`state`,`effective_from`);--> statement-breakpoint
CREATE TABLE `organisation_role_permissions` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_role_id` text NOT NULL,
	`permission_code` text NOT NULL,
	`record_scope` text NOT NULL,
	`effect` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_role_id`) REFERENCES `organisation_roles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`permission_code`) REFERENCES `access_permissions`(`code`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_org_role_permission` ON `organisation_role_permissions` (`organisation_role_id`,`permission_code`);--> statement-breakpoint
CREATE TABLE `organisation_roles` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`name` text NOT NULL,
	`description` text NOT NULL,
	`version` integer DEFAULT 1 NOT NULL,
	`branch_scope` text DEFAULT '[]' NOT NULL,
	`approval_limit_cents` integer,
	`status` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_org_roles_name_version` ON `organisation_roles` (`organisation_id`,`name`,`version`);--> statement-breakpoint
CREATE INDEX `idx_org_roles_status` ON `organisation_roles` (`organisation_id`,`status`);--> statement-breakpoint
CREATE TABLE `positions` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`job_title_id` text NOT NULL,
	`department_id` text,
	`business_unit_id` text,
	`branch_id` text,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`status` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`job_title_id`) REFERENCES `job_titles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`business_unit_id`) REFERENCES `business_units`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_positions_org_code` ON `positions` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `sod_rules` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`action_set` text NOT NULL,
	`scope` text NOT NULL,
	`mandatory` integer DEFAULT 1 NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_sod_rule_code_org` ON `sod_rules` (`code`,`organisation_id`);--> statement-breakpoint
CREATE TABLE `sod_violations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`sod_rule_id` text NOT NULL,
	`actor_id` text NOT NULL,
	`resource_type` text NOT NULL,
	`resource_id` text NOT NULL,
	`status` text NOT NULL,
	`evidence` text NOT NULL,
	`detected_at` text NOT NULL,
	`resolved_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`sod_rule_id`) REFERENCES `sod_rules`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_sod_violations_org_status` ON `sod_violations` (`organisation_id`,`status`,`detected_at`);--> statement-breakpoint
CREATE TABLE `subscriptions` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`provider` text NOT NULL,
	`provider_reference` text NOT NULL,
	`status` text NOT NULL,
	`activated_at` text,
	`current_period_start` text NOT NULL,
	`current_period_end` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_subscription_provider_ref` ON `subscriptions` (`provider`,`provider_reference`);--> statement-breakpoint
CREATE INDEX `idx_subscription_org_status` ON `subscriptions` (`organisation_id`,`status`);--> statement-breakpoint
CREATE TABLE `user_capability_assignments` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`user_id` text NOT NULL,
	`capability` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`assigned_by` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_user_capability` ON `user_capability_assignments` (`organisation_id`,`user_id`,`capability`);--> statement-breakpoint
CREATE TABLE `user_role_assignments` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`user_id` text NOT NULL,
	`employee_id` text,
	`organisation_role_id` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`assigned_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_role_id`) REFERENCES `organisation_roles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_user_roles_subject_effective` ON `user_role_assignments` (`organisation_id`,`user_id`,`status`,`effective_from`);--> statement-breakpoint
CREATE TABLE `workflow_approvals` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_instance_id` text NOT NULL,
	`workflow_assignment_id` text NOT NULL,
	`workflow_version_id` text NOT NULL,
	`actor_id` text NOT NULL,
	`decision` text NOT NULL,
	`reason` text NOT NULL,
	`authority_snapshot` text NOT NULL,
	`decided_at` text NOT NULL,
	FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`workflow_assignment_id`) REFERENCES `workflow_assignments`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`workflow_version_id`) REFERENCES `workflow_versions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_workflow_approval_assignment` ON `workflow_approvals` (`workflow_assignment_id`);--> statement-breakpoint
CREATE TABLE `workflow_assignments` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_instance_id` text NOT NULL,
	`node_key` text NOT NULL,
	`assigned_user_id` text,
	`assigned_role_id` text,
	`status` text NOT NULL,
	`due_at` text,
	`assigned_at` text NOT NULL,
	FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_role_id`) REFERENCES `organisation_roles`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_workflow_assignments_queue` ON `workflow_assignments` (`assigned_user_id`,`status`,`due_at`);--> statement-breakpoint
CREATE TABLE `workflow_conditions` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_transition_id` text NOT NULL,
	`field` text NOT NULL,
	`operator` text NOT NULL,
	`comparison_value` text NOT NULL,
	FOREIGN KEY (`workflow_transition_id`) REFERENCES `workflow_transitions`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE TABLE `workflow_delegations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`delegator_user_id` text NOT NULL,
	`delegate_user_id` text NOT NULL,
	`workflow_id` text,
	`scope` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text NOT NULL,
	`approved_by` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`delegator_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`delegate_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`workflow_id`) REFERENCES `workflows`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_workflow_delegations_effective` ON `workflow_delegations` (`organisation_id`,`delegate_user_id`,`status`,`effective_from`);--> statement-breakpoint
CREATE TABLE `workflow_instances` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`workflow_version_id` text NOT NULL,
	`resource_type` text NOT NULL,
	`resource_id` text NOT NULL,
	`initiated_by` text NOT NULL,
	`status` text NOT NULL,
	`current_node_key` text NOT NULL,
	`context_snapshot` text NOT NULL,
	`started_at` text NOT NULL,
	`completed_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`workflow_version_id`) REFERENCES `workflow_versions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`initiated_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_workflow_instances_resource` ON `workflow_instances` (`organisation_id`,`resource_type`,`resource_id`);--> statement-breakpoint
CREATE TABLE `workflow_nodes` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_version_id` text NOT NULL,
	`node_key` text NOT NULL,
	`node_type` text NOT NULL,
	`label` text NOT NULL,
	`assignee_type` text,
	`assignee_reference` text,
	`sequence` integer NOT NULL,
	FOREIGN KEY (`workflow_version_id`) REFERENCES `workflow_versions`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_workflow_node_key` ON `workflow_nodes` (`workflow_version_id`,`node_key`);--> statement-breakpoint
CREATE TABLE `workflow_transitions` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_version_id` text NOT NULL,
	`from_node_key` text NOT NULL,
	`to_node_key` text NOT NULL,
	`sequence` integer NOT NULL,
	FOREIGN KEY (`workflow_version_id`) REFERENCES `workflow_versions`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_workflow_transition` ON `workflow_transitions` (`workflow_version_id`,`from_node_key`,`to_node_key`);--> statement-breakpoint
CREATE TABLE `workflow_versions` (
	`id` text PRIMARY KEY NOT NULL,
	`workflow_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`version_number` integer NOT NULL,
	`status` text NOT NULL,
	`definition_hash` text NOT NULL,
	`definition` text NOT NULL,
	`effective_from` text,
	`published_by` text,
	`approved_by` text,
	`published_at` text,
	`retired_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`workflow_id`) REFERENCES `workflows`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`published_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_workflow_versions_number` ON `workflow_versions` (`workflow_id`,`version_number`);--> statement-breakpoint
CREATE INDEX `idx_workflow_versions_effective` ON `workflow_versions` (`organisation_id`,`status`,`effective_from`);--> statement-breakpoint
CREATE TABLE `workflows` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`name` text NOT NULL,
	`domain_action` text NOT NULL,
	`status` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_workflows_org_name` ON `workflows` (`organisation_id`,`name`);--> statement-breakpoint
CREATE INDEX `idx_workflows_org_status` ON `workflows` (`organisation_id`,`status`);
--> statement-breakpoint
CREATE TRIGGER `enforce_employee_seat_limit_insert`
BEFORE INSERT ON `employees` WHEN NEW.`status` IN ('ACTIVE','INVITED')
BEGIN
	SELECT CASE WHEN
		(SELECT COUNT(*) FROM `employees` e WHERE e.`organisation_id`=NEW.`organisation_id` AND e.`status` IN ('ACTIVE','INVITED')) >=
		COALESCE((SELECT pe.`limit_value` FROM `organisation_licenses` ol
			JOIN `license_plan_entitlements` pe ON pe.`license_plan_id`=ol.`license_plan_id` AND pe.`feature_key`='USER_SEATS' AND pe.`enabled`=1
			WHERE ol.`organisation_id`=NEW.`organisation_id` ORDER BY ol.`effective_from` DESC LIMIT 1),0)
		THEN RAISE(ABORT,'USER_SEAT_LIMIT_EXCEEDED') END;
END;
--> statement-breakpoint
CREATE TRIGGER `enforce_employee_seat_limit_update`
BEFORE UPDATE OF `status`,`organisation_id` ON `employees`
WHEN NEW.`status` IN ('ACTIVE','INVITED') AND OLD.`status` NOT IN ('ACTIVE','INVITED')
BEGIN
	SELECT CASE WHEN
		(SELECT COUNT(*) FROM `employees` e WHERE e.`organisation_id`=NEW.`organisation_id` AND e.`status` IN ('ACTIVE','INVITED')) >=
		COALESCE((SELECT pe.`limit_value` FROM `organisation_licenses` ol
			JOIN `license_plan_entitlements` pe ON pe.`license_plan_id`=ol.`license_plan_id` AND pe.`feature_key`='USER_SEATS' AND pe.`enabled`=1
			WHERE ol.`organisation_id`=NEW.`organisation_id` ORDER BY ol.`effective_from` DESC LIMIT 1),0)
		THEN RAISE(ABORT,'USER_SEAT_LIMIT_EXCEEDED') END;
END;
