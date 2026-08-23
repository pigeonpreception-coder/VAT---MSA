CREATE UNIQUE INDEX `ux_taxpayers_tin` ON `taxpayers` (`tin`);
--> statement-breakpoint
CREATE TRIGGER identity_proofing_authority_guard_insert
BEFORE INSERT ON identity_proofing_cases
WHEN NEW.status='AUTHORITY_VERIFIED' AND (
  NEW.provider<>'ITAS'
  OR NEW.provider_environment NOT IN ('PRODUCTION_EQUIVALENT','PRODUCTION')
  OR NEW.matched_taxpayer_id IS NULL
  OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32
  OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_AUTHORITY_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_authority_guard_update
BEFORE UPDATE OF status,provider,provider_environment,matched_taxpayer_id,evidence_hash,reviewed_by,reviewed_at ON identity_proofing_cases
WHEN NEW.status='AUTHORITY_VERIFIED' AND (
  NEW.provider<>'ITAS'
  OR NEW.provider_environment NOT IN ('PRODUCTION_EQUIVALENT','PRODUCTION')
  OR NEW.matched_taxpayer_id IS NULL
  OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32
  OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_AUTHORITY_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_synthetic_guard_insert
BEFORE INSERT ON identity_proofing_cases
WHEN NEW.status='SYNTHETIC_MATCHED' AND (
  NEW.provider_environment<>'SYNTHETIC_TEST'
  OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_SYNTHETIC_EVIDENCE_INVALID'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_synthetic_guard_update
BEFORE UPDATE OF status,provider_environment,evidence_hash ON identity_proofing_cases
WHEN NEW.status='SYNTHETIC_MATCHED' AND (
  NEW.provider_environment<>'SYNTHETIC_TEST'
  OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_SYNTHETIC_EVIDENCE_INVALID'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_verified_immutable
BEFORE UPDATE ON identity_proofing_cases
WHEN OLD.status='AUTHORITY_VERIFIED' AND (
  NEW.status<>OLD.status OR NEW.provider<>OLD.provider
  OR NEW.provider_environment<>OLD.provider_environment
  OR COALESCE(NEW.provider_reference,'')<>COALESCE(OLD.provider_reference,'')
  OR COALESCE(NEW.matched_taxpayer_id,'')<>COALESCE(OLD.matched_taxpayer_id,'')
  OR COALESCE(NEW.evidence_hash,'')<>COALESCE(OLD.evidence_hash,'')
  OR NEW.confidence_bps<>OLD.confidence_bps
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_AUTHORITY_DECISION_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_no_delete
BEFORE DELETE ON identity_proofing_cases
BEGIN SELECT RAISE(ABORT,'IDENTITY_PROOFING_HISTORY_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_reconciliation_no_update
BEFORE UPDATE ON identity_reconciliation_candidates
BEGIN SELECT RAISE(ABORT,'IDENTITY_RECONCILIATION_EVIDENCE_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_reconciliation_no_delete
BEFORE DELETE ON identity_reconciliation_candidates
BEGIN SELECT RAISE(ABORT,'IDENTITY_RECONCILIATION_EVIDENCE_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_event_no_update
BEFORE UPDATE ON identity_proofing_events
BEGIN SELECT RAISE(ABORT,'IDENTITY_PROOFING_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_proofing_event_no_delete
BEFORE DELETE ON identity_proofing_events
BEGIN SELECT RAISE(ABORT,'IDENTITY_PROOFING_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER identity_mismatch_no_self_resolution
BEFORE UPDATE OF status,resolved_by,resolved_at ON identity_mismatch_cases
WHEN NEW.status IN ('RESOLVED','REJECTED') AND EXISTS (
  SELECT 1 FROM identity_proofing_cases p
  WHERE p.id=NEW.proofing_case_id AND (NEW.resolved_by IS NULL OR NEW.resolved_by=p.requested_by)
)
BEGIN SELECT RAISE(ABORT,'IDENTITY_MISMATCH_INDEPENDENT_REVIEW_REQUIRED'); END;
--> statement-breakpoint
INSERT INTO identity_proofing_cases
  (id,subject_type,subject_reference,registration_application_id,provider,provider_environment,provider_reference,status,
   confidence_bps,matched_taxpayer_id,evidence_hash,reason_code,requested_by,reviewed_by,created_at,updated_at,reviewed_at)
SELECT 'proof-' || r.id,'TAXPAYER_REGISTRATION',r.id,r.id,'ITAS','CONTRACT_PENDING',
  'itas-contract-pending:' || r.id,'PENDING_PROVIDER',0,NULL,NULL,'AUTHORITATIVE_PROVIDER_CONTRACT_REQUIRED',r.submitted_by,NULL,
  CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL
FROM registration_applications r
WHERE NOT EXISTS (SELECT 1 FROM identity_proofing_cases p WHERE p.registration_application_id=r.id);
--> statement-breakpoint
INSERT INTO identity_proofing_events
  (id,proofing_case_id,event_type,from_status,to_status,confidence_bps,reason_code,evidence_hash,actor_id,occurred_at)
SELECT 'proof-event-' || p.registration_application_id,p.id,'IdentityProofingRequested',NULL,'PENDING_PROVIDER',0,
  'AUTHORITATIVE_PROVIDER_CONTRACT_REQUIRED',NULL,p.requested_by,CURRENT_TIMESTAMP
FROM identity_proofing_cases p
WHERE p.registration_application_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM identity_proofing_events e WHERE e.proofing_case_id=p.id);
--> statement-breakpoint
INSERT INTO app_schema_revisions (revision,applied_at,source)
VALUES ('issue2-identity-proofing-2026-08-23',CURRENT_TIMESTAMP,'DRIZZLE_MIGRATION_0017');
