CREATE TABLE `expense_decisions` (
	`id` text PRIMARY KEY NOT NULL,
	`expense_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`decision` text NOT NULL,
	`reason` text NOT NULL,
	`decided_by` text NOT NULL,
	`decided_at` text NOT NULL,
	FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`decided_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_expense_decisions_expense` ON `expense_decisions` (`expense_id`);--> statement-breakpoint
CREATE INDEX `idx_expense_decisions_organisation` ON `expense_decisions` (`organisation_id`,`decided_at`);--> statement-breakpoint
UPDATE `expenses` SET `created_by`='usr-tp1-owner'
WHERE `id`='expense-0001' AND `created_by`=`approved_by`;--> statement-breakpoint
CREATE TRIGGER `enforce_expense_decision_insert`
BEFORE INSERT ON `expense_decisions`
BEGIN
	SELECT CASE WHEN NEW.decision NOT IN ('APPROVE','REJECT') THEN RAISE(ABORT,'EXPENSE_DECISION_INVALID') END;
	SELECT CASE WHEN length(trim(NEW.reason)) < 5 OR length(NEW.reason) > 500 THEN RAISE(ABORT,'EXPENSE_DECISION_REASON_INVALID') END;
	SELECT CASE WHEN NOT EXISTS (
		SELECT 1 FROM `expenses` e WHERE e.id=NEW.expense_id AND e.organisation_id=NEW.organisation_id AND e.status='DRAFT'
	) THEN RAISE(ABORT,'EXPENSE_NOT_DECIDABLE') END;
	SELECT CASE WHEN EXISTS (
		SELECT 1 FROM `expenses` e WHERE e.id=NEW.expense_id AND e.created_by=NEW.decided_by
	) THEN RAISE(ABORT,'EXPENSE_SELF_APPROVAL_DENIED') END;
END;--> statement-breakpoint
CREATE TRIGGER `apply_expense_decision`
AFTER INSERT ON `expense_decisions`
BEGIN
	UPDATE `expenses` SET
		status=CASE NEW.decision WHEN 'APPROVE' THEN 'APPROVED' ELSE 'REJECTED' END,
		approved_by=CASE NEW.decision WHEN 'APPROVE' THEN NEW.decided_by ELSE NULL END,
		approved_at=CASE NEW.decision WHEN 'APPROVE' THEN NEW.decided_at ELSE NULL END
	WHERE id=NEW.expense_id AND organisation_id=NEW.organisation_id AND status='DRAFT';
END;--> statement-breakpoint
CREATE TRIGGER `prevent_expense_self_approval_insert`
BEFORE INSERT ON `expenses`
WHEN NEW.status='APPROVED' AND NEW.approved_by=NEW.created_by
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_SELF_APPROVAL_DENIED');
END;--> statement-breakpoint
CREATE TRIGGER `prevent_expense_self_approval`
BEFORE UPDATE OF status,approved_by,created_by ON `expenses`
WHEN NEW.status='APPROVED' AND NEW.approved_by=NEW.created_by
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_SELF_APPROVAL_DENIED');
END;--> statement-breakpoint
CREATE TRIGGER `enforce_expense_decision_transition`
BEFORE UPDATE OF status ON `expenses`
WHEN OLD.status='DRAFT' AND NEW.status IN ('APPROVED','REJECTED') AND NOT EXISTS (
	SELECT 1 FROM `expense_decisions` d WHERE d.expense_id=NEW.id AND d.organisation_id=NEW.organisation_id
)
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_DECISION_REQUIRED');
END;--> statement-breakpoint
CREATE TRIGGER `prevent_expense_terminal_status_change`
BEFORE UPDATE OF status ON `expenses`
WHEN OLD.status IN ('APPROVED','REJECTED') AND NEW.status<>OLD.status
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_TERMINAL_STATE_IMMUTABLE');
END;--> statement-breakpoint
CREATE TRIGGER `prevent_expense_decision_update`
BEFORE UPDATE ON `expense_decisions`
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_DECISION_IMMUTABLE');
END;--> statement-breakpoint
CREATE TRIGGER `prevent_expense_decision_delete`
BEFORE DELETE ON `expense_decisions`
BEGIN
	SELECT RAISE(ABORT,'EXPENSE_DECISION_IMMUTABLE');
END;
