# NiyiGuard AI Agile Roadmap

This document is the execution roadmap for building NiyiGuard as a modern WordPress security solution with AI-assisted development.

---

## Agile Setup

- Sprint length: 2 weeks
- Ceremony cadence:
  - Sprint planning: Day 1
  - Mid-sprint review: Day 7
  - Sprint demo + retrospective: Day 14
- Delivery model: Incremental MVP first, then hardening, then scale features
- Tracking: One GitHub issue per task, grouped by sprint milestone

## Definition of Done (DoD)

A task is complete only when:

- [ ] Code and docs are updated
- [ ] Test cases are added/updated
- [ ] Lint/static checks pass
- [ ] Security implications reviewed
- [ ] Issue is linked to PR and closed

---

## Sprint 0 - Project Initialization (Week 0-1)

Goal: Set up foundations for clean, secure, and AI-friendly delivery.

- [ ] Finalize product scope and MVP boundary
- [ ] Create repository labels (security, middleware, auth, docs, bug, enhancement)
- [ ] Create issue templates (feature, bug, security task)
- [ ] Define coding standards and branch naming conventions
- [ ] Configure local dev baseline (PHP version, Composer, test stack)
- [ ] Define architecture decision record format (ADR)
- [ ] Create backlog grooming board in GitHub Projects

Exit criteria:

- [ ] Backlog has sprint-ready tasks
- [ ] Team conventions documented

---

## Sprint 1 - Foundation and Plugin Skeleton (Week 1-2)

Goal: Create base plugin architecture.

- [ ] Initialize plugin bootstrap entry point
- [ ] Set up Composer and PSR-4 autoloading
- [ ] Implement lightweight service container
- [ ] Add configuration loader (`config/`)
- [ ] Add logging abstraction and storage strategy
- [ ] Create base module registration pattern
- [ ] Add minimal admin settings page skeleton

Exit criteria:

- [ ] Plugin activates without errors
- [ ] Core services resolve from container
- [ ] Logging works for basic events

---

## Sprint 2 - Security Middleware Core (Week 3-4)

Goal: Build request security pipeline.

- [ ] Implement middleware contract/interface
- [ ] Build middleware pipeline runner
- [ ] Add request interception lifecycle hooks
- [ ] Implement middleware priority and ordering
- [ ] Add response short-circuit support
- [ ] Create basic telemetry for middleware outcomes
- [ ] Document middleware developer API

Exit criteria:

- [ ] Multiple middleware classes execute in deterministic order
- [ ] Middleware can block/allow requests reliably

---

## Sprint 3 - Authentication and Abuse Prevention (Week 5-6)

Goal: Protect login and authentication surfaces.

- [ ] Implement login rate limiter
- [ ] Implement failed-login tracking
- [ ] Implement temporary lockouts (IP + username strategy)
- [ ] Add suspicious login detection rules
- [ ] Add XML-RPC and REST abuse throttling
- [ ] Add admin notifications for critical auth events
- [ ] Add integration tests for auth hardening flows

Exit criteria:

- [ ] Brute-force and burst attacks are throttled
- [ ] Lockout behavior is configurable and test-covered

---

## Sprint 4 - CSRF, Signed URLs, and Secure Headers (Week 7-8)

Goal: Deliver request integrity controls.

- [ ] Implement CSRF token service with expiration
- [ ] Add CSRF middleware validation
- [ ] Add form helper APIs for token injection
- [ ] Implement signed URL generation and verification
- [ ] Add expiring signed links for sensitive actions
- [ ] Implement security headers module with presets
- [ ] Add compatibility tests for common WordPress flows

Exit criteria:

- [ ] Critical write actions enforce token/signature validation
- [ ] Security headers are configurable and safely applied

---

## Sprint 5 - Audit Logging and Admin Experience (Week 9-10)

Goal: Give operators visibility and control.

- [ ] Implement audit log schema and storage
- [ ] Log admin actions and auth events
- [ ] Build log listing/filtering UI
- [ ] Add export function for audit logs
- [ ] Add retention policy settings
- [ ] Add operational dashboard (threat counters, top alerts)
- [ ] Add privacy/data retention documentation

Exit criteria:

- [ ] Admin can inspect and export key security events
- [ ] Log retention is configurable

---

## Sprint 6 - WooCommerce Security Pack (Week 11-12)

Goal: Secure commerce workflows.

- [ ] Add checkout abuse throttling
- [ ] Add coupon abuse detection baseline
- [ ] Add registration spam protection
- [ ] Add bot/cart anomaly checks
- [ ] Add WooCommerce-specific event logging
- [ ] Add test matrix for WooCommerce compatibility
- [ ] Write WooCommerce integration docs

Exit criteria:

- [ ] Checkout and registration abuse is measurably reduced
- [ ] WooCommerce compatibility tests pass

---

## Sprint 7 - Hardening, Beta, and Launch Readiness (Week 13-14)

Goal: Prepare for beta release.

- [ ] Run full security review against OWASP-style checklist
- [ ] Add regression and performance test passes
- [ ] Validate shared-hosting compatibility
- [ ] Complete docs (setup, usage, extension APIs)
- [ ] Create beta onboarding and feedback loop
- [ ] Prepare release checklist and changelog
- [ ] Tag and publish beta release

Exit criteria:

- [ ] Beta package is stable and documented
- [ ] Feedback loop is active for next planning cycle

---

## AI-Assisted Workflow Checklist

Use AI to accelerate delivery while keeping human review gates.

- [ ] AI generates first-pass issue breakdown from this roadmap
- [ ] AI drafts implementation stubs and tests per sprint task
- [ ] Human reviews all security-sensitive code paths
- [ ] AI drafts documentation and migration notes
- [ ] Human signs off release checklist before tagging

---

## GitHub Issue Seed List (Create 1 issue per task)

Recommended issue title format:

`[Sprint X] <task title>`

Recommended labels:

- `sprint-x`
- `security`
- `feature` or `enhancement`

Milestone naming suggestion:

- `Sprint 0 - Initialization`
- `Sprint 1 - Foundation`
- `Sprint 2 - Middleware Core`
- `Sprint 3 - Auth Protection`
- `Sprint 4 - Integrity Controls`
- `Sprint 5 - Audit and UI`
- `Sprint 6 - WooCommerce`
- `Sprint 7 - Beta Launch`
