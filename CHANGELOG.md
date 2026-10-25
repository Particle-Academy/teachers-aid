# Changelog

Notable changes to `particle-academy/teachers-aid`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## [Unreleased]

## 0.3.0 — 2026-10-24

TAC can now manage an existing catalog, not just add to it, and can be run by
a runtime that owns the tool loop (prism-harness).

### Added

- **Read tools** — `catalog_overview`, `catalog_search`, `catalog_get`. Before
  these the agent had no way to learn an id, so "rename the Trespass course"
  became a proposal to create a second one. They read and never write.
- **More proposals** — `propose_module`, `propose_create` (any configured
  entity), `propose_delete`, `propose_attach` / `propose_detach` (e.g. a course
  into a curriculum). `propose_course` takes delivery mode, price, hours,
  attendance hours and highlights; `propose_test` attaches to a course, module
  or lesson; `propose_lesson` takes a module.
- `ChangeOperation::attach()` / `detach()`, applied through relations named in
  the new `teachers-aid.relations` config — the blast radius for links, as
  `entities` is for records.
- **`GuardsDeletion`** — a host veto over deletes, asked when the delete is
  proposed and again when it is applied. The default allows everything, which
  is what the applier always did.
- `TeachersAid::tools(ChangePlan)`, `systemPrompt()`, `prompt()` and
  `TOOL_NAMES`, so a runtime that owns the loop can run TAC; `PrismTools::live()`
  turns a tool into a Prism tool that does the work. Pass the pending plan to
  `tools()` to keep building on it across turns.
- `module` in the default `entities` config.

### Changed

- **Proposals are checked when made.** A field the entity does not have, an id
  that does not exist, `is_published`, a reused `ref` or a `$ref` nothing
  created is answered "Refused: …" to the model, instead of being recorded and
  failing when the teacher presses Apply. An entity missing from `entities` is
  refused the same way — **if your config omits `question`, `propose_question`
  now refuses rather than recording an operation that could never apply.**
- `TeachersAid`'s driver is optional and may be a closure; only `respondTo()`
  needs one.
- `propose_lesson` no longer forces `content_type: text`; the column default
  applies.

Propose-then-apply is unchanged: every tool that changes anything still only
records a proposal, and `PlanApplier` is still the only writer.

## 0.2.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


## 0.1.0 — 2026-08-01

**First published release.** The TAC authoring agent: reads course material and *proposes* curriculum changes. LLM-library agnostic behind a single `ChatDriver` seam. Propose-then-apply is structural — tools hold no model, repository or connection, so there is no code path from a tool call to a write; `PlanApplier` is the only writer and never publishes.

### Added

- **CI** — matching the rest of the Fancy kit.
- This changelog. Entries start here rather than being reconstructed after the
  fact: the reasoning behind the earlier commits has already evaporated, and
  inventing it would be worse than admitting the gap.

