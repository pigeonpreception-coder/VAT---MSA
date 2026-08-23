# Failure and exception handling

| Condition | Safe behavior | Recovery | Audit/alert |
|---|---|---|---|
| Ordinary employee submits commercial signup | Deny before persistence with generic `COMPANY_ADMIN_AUTHORITY_REQUIRED` | Use invitation/sign-in path | Rate-limited denial event |
| Duplicate commercial application | Return idempotent prior result or conflict; do not duplicate organisation | Verified review/merge process | Application conflict event |
| Payment unavailable or unapproved | Remain pending; never activate licence | Retry only through approved adapter | Provider health and failed attempt |
| Payment callback replay/mismatch | Reject and quarantine evidence | Provider/security review | High-severity fraud signal |
| Seat race at final capacity | Database permits at most remaining seats; losing transactions roll back | Deactivate user or activate upgrade | Capacity denial metric/event |
| Downgrade below active use | Open capacity exception; no deletion | Admin deactivates users or upgrades | Exception open/resolved events |
| Commercial licence expires | Deny mutation; retain authorized read/export/compliance continuity | Renew/reactivate through approved flow | Expiry access decision |
| Tax adapter unavailable | Fail closed for new federation/verification | Approved direct path or later retry | Country adapter SLO alert |
| Tax identity ambiguous | No new identity and no tax session | Authority-reviewed canonical link | Identity conflict event |
| Authority subscription inactive | Deny government tax operation | Authorized authority activation | Tax denial event |
| Tax authorization suspended/revoked | Deny tax feature only | Authority reinstatement/new authorization | Suspension/revocation event |
| Cross-domain plan-feature assignment | Database rejects transaction | Correct catalogue data under four-eyes control | Critical configuration alert |
| Cross-tenant/jurisdiction access | Fail closed without existence disclosure | None; security investigation | High-severity security alert |
| Audit sink unavailable | Business transaction writes durable local outbox/audit record atomically or fails for privileged actions | Replay after recovery | Platform availability alert |
| Offline evidence expired/conflicted | Quarantine, do not authorize or silently overwrite | Online revalidation/reconciliation | Sync conflict event |

API errors use stable machine codes, a safe human message, correlation ID and retryability indicator. Stack traces, identifiers from other tenants and entitlement counts are never exposed publicly.
