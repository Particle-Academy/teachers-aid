# Changelog

Notable changes to `particle-academy/teachers-aid`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## [Unreleased]

## 0.1.0 — 2026-08-01

**First published release.** The TAC authoring agent: reads course material and *proposes* curriculum changes. LLM-library agnostic behind a single `ChatDriver` seam. Propose-then-apply is structural — tools hold no model, repository or connection, so there is no code path from a tool call to a write; `PlanApplier` is the only writer and never publishes.

### Added

- **CI** — matching the rest of the Fancy kit.
- This changelog. Entries start here rather than being reconstructed after the
  fact: the reasoning behind the earlier commits has already evaporated, and
  inventing it would be worse than admitting the gap.

