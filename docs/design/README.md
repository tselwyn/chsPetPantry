# PFPMS design record

These documents are the analysis and design work behind [`../PFPMS_Implementation_Plan.md`](../PFPMS_Implementation_Plan.md), the approved plan. When this detail and the plan disagree, the plan wins: it includes the corrections from the adversarial reviews (files 30–33). Secret values are redacted. Line references point at the `legacy-baseline` git tag.

| File | What it is |
|---|---|
| 01-analysis-use-cases.md | UC-01 to UC-16: each flow, the tables it uses, cross-cutting requirements, schema gaps, and out-of-scope items |
| 02-analysis-user-stories.md | All 41 stories with MoSCoW, schema support, and dependency order |
| 03-analysis-legacy-pages.md | The 156 legacy root pages classified DELETE / ADAPT / REWRITE, plus how header and index gating work |
| 04-analysis-data-layer.md | Legacy `database/`, `domain/` and `include/`; mapping of legacy tables to v2 |
| 05-analysis-infra-security.md | Auth and session flaws, exposed secrets, email, tooling, and MariaDB vs MySQL risk |
| 06-analysis-erd.md | Check that the ERD diagrams and the SQL match; extras in the use-case diagram |
| 10-design-foundation.md | Phase 0 hygiene and the shared core (detailed) |
| 11-design-features.md | Delivery plan module by module (detailed) |
| 12-design-offline-pwa.md | Offline Station PWA design (detailed) |
| 20-merged-plan-pre-review.md | Merged plan **before** the review corrections |
| 30–33 review-*.md | Adversarial reviews: schema and DB, repo facts, requirements coverage, and hosting/offline/security |
| 40-design-devices.md | Tablet administration (P2A `admin_devices`, as built) and the contract Phase 2B must follow: registration codes, Retire and Erase now, locks, the trusted-device test |
