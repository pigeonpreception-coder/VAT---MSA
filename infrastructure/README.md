# Infrastructure reference baseline

These manifests express security and resilience invariants for an approved Kubernetes target; they are not a complete sovereign production environment. Cloud/project IDs, registry, workload identity, database/queue, KMS, ingress, certificates, observability and policy engines must be supplied by the selected platform through environment overlays.

The baseline uses three stateless replicas, non-root/read-only containers, seccomp, dropped capabilities, resource budgets, liveness/readiness probes, topology spread, a disruption budget, autoscaling and default-deny networking. An ingress/API-gateway overlay must restrict origin access to the protected edge. Egress must be narrowed to named private dependencies rather than the illustrative DNS/HTTPS baseline.

Apply only after image digest/signature verification, admission-policy validation and namespace RBAC are configured. Never store Secret values in these files; bind workload identity or external secret references at runtime.
