# ADR-003: ITAS as preferred identity provider

**Status:** Proposed — **requires ITAS/NAMRA confirmation**.

## Context

VAT-MSA must not create a competing government identity when ITAS can provide authoritative taxpayer authentication and attributes.

## Decision

Prefer ITAS federation through a standards-based protocol (OIDC/OAuth 2.1 or SAML only if mandated). The immutable identity key is issuer plus subject, never email. Retain assurance level, authentication time and source of each authoritative claim. VAT-MSA remains the authorization policy decision point for its resources.

## Consequences

Single sign-on and lifecycle alignment improve, while ITAS becomes a critical dependency. A broker/adapter isolates protocol changes; circuit breaking and approved continuity apply. Account-link collisions are quarantined.

## Alternatives rejected

Screen scraping, shared password databases and trusting unsigned identity payloads are prohibited.

## Gate

Protocol, claims, assurance, logout, revocation, availability, support and legal authority are **NOT READY** until confirmed.

