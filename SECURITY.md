# Security policy

The governing target architecture is `08-enterprise-architecture/33-global-security-privacy-compliance-architecture.md` and its documents 34-38, control/test/evidence catalogues and ADR-025 through ADR-029. Those artifacts describe alignment targets and do not claim certification, legal compliance or implemented operating effectiveness.

Protected changes require server-side authorization. Privileged changes require step-up authentication, bounded JIT access and independent approval. Self-approval and emergency SoD overrides are prohibited. Technical break-glass is limited to approved containment/recovery actions and cannot grant fiscal authority, weaken tenant isolation or suppress evidence.

Do not place vulnerability details in public issues. Report suspected vulnerabilities privately to the VAT-MSA security owner designated for the deployment environment. Include the affected version/commit, reproduction steps, impact and safe contact details; do not include live taxpayer data or credentials.

Only test environments and accounts for which you have explicit authorization. Do not perform denial-of-service, social engineering, persistence, destructive testing or production data access without a signed scope and rules of engagement.

The project targets supported dependency releases and blocks known critical vulnerabilities from promotion unless an accountable authority records a time-bound exception and compensating control. Security fixes receive priority based on exploitability, data/availability impact and exposure. Production operators must maintain an emergency revocation and rollback path.

If a credential is discovered, stop using it, notify the security owner through an approved private channel, preserve minimal evidence and rotate/revoke it. Never commit the credential in a report or test.
