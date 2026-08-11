# VAT-MSA security and privacy profiles

These files are architecture contracts for compliance-as-code. They are not production configuration, legal opinions, certifications or active controls.

Merge order:

`platform invariants -> global baseline -> regional profile -> country profile -> organisation policy -> session obligations`

Rules:

1. Every profile is schema-validated, canonicalized, hashed, signed and independently approved before executable use.
2. A child profile can tighten but cannot weaken a mandatory parent value.
3. Unknown mandatory values, invalid/expired signatures, incompatible schema, conflicting jurisdiction or downgrade attempts fail closed.
4. Author, reviewer, approver, signer and activator are separated; no self-approval and no emergency SoD override.
5. Privileged profile changes require step-up authentication and immutable evidence.
6. Legal/regulatory text is encoded only after an authorized applicability/interpretation decision.
7. `executable: false` and `productionEnabled: false` remain until the relevant security and country-readiness gates approve activation.

The global baseline is normative for design. The Namibia, EU and USA manifests are non-executable applicability placeholders and cannot activate services or legal rules.
