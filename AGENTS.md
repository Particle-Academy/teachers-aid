# AGENTS.md — teachers-aid

The TAC authoring agent: reads course material and **proposes** curriculum,
course and test changes. It never publishes. `CLAUDE.md` symlinks here.

The React surface is `@particle-academy/teachers-aid-ui`. The course schema it is
shaped for is `particle-academy/laravel-courses` — a **`suggest`, not a
`require`**, because entities are host-configured and the suite proves it by
running against stand-in models with laravel-courses absent.

## Two properties that are structural, not conventions

**1. Propose-then-apply.** The `propose_*` tools append to a `ChangePlan` and
hold **no writer**. They and the `catalog_*` read tools go through
`Support\EntityCatalog`, which builds queries and never saves — eyes, not hands.
There is no code path from a tool call to a write. `PlanApplier` is the only
writer, it takes a `ChangePlan`, and it never publishes.

That matters because the input is untrusted by construction: TAC reads uploaded
handbooks, decks and spreadsheets. **A prompt injection inside an uploaded file
still cannot change anything** — the worst it can do is produce a bad *proposal*
a human then rejects. Do not "simplify" this by handing a tool a repository.

**2. LLM-library agnostic.** Agent, tools and plan model import no LLM library.
Two ways to run it, and the TOOLS are the same objects in both:

- `TeachersAid::respondTo()` — the loop here, over a `Contracts\ChatDriver`.
  **The loop lives in `TeachersAid`, not the driver**, so behaviour is identical
  whichever library a host swaps in. Keep the loop out of drivers.
- A runtime that owns the loop (prism-harness): `tools($plan)` + `systemPrompt()`,
  each tool made runnable by `Drivers\PrismTools::live()`. `PrismTools::inert()`
  is the driver's flavour — there TeachersAid runs the handler, so Prism must not.

## The shape

```
Message (+ Attachment) → TeachersAid::respondTo() → TurnResult
                              │
                       ChatDriver (Prism, or yours)
                              │
                    CourseAuthoringTools → ChangePlan
                                                │
                                          PlanApplier  ← the ONLY writer
```

- `Chat\` — `Message`, `Attachment`, `ChatResponse`, `ToolCall`, `ToolDefinition`.
- `Contracts\ExtractsText` — the seam for reading uploads. `last-word` (.docx),
  `holy-sheet` (.xlsx/.csv) and `dark-slide` (.pptx) are all `suggest`-only.
- `Plan\` — `ChangePlan`, `ChangeOperation`, `PlanApplier`.

## Rules

- **Authorization is the HOST's job here, and that is correct.** `PlanApplier`
  writes through Eloquent — the same layer `laravel-courses`' own
  `EnrollmentService` and `CertificateService` write at — so it does **not**
  consult `AuthorizesCourseAdmin`, which gates that package's HTTP layer.
  **Gate the route that invokes the applier.** Do not hand the applier a faked
  `Request` to make it consult the contract: a check that looks like enforcement
  but isn't is worse than no check.
- **Attach tests at any level.** `propose_test` exposes `course_id`, `module_id`
  and `lesson_id`, and as of laravel-courses 0.1.0 all three count toward
  progress and completion. (They did not always — a module- or lesson-level quiz
  used to be invisible, so an enrollment could report complete with it unpassed.
  Fixed upstream rather than worked around here.)
- **Never let a driver own the tool loop.** See above.
- **Check proposals when they are made.** A wrong field, id or `$ref` is
  answered `Refused: …` to the model so it can fix it in the conversation.
  Recording it anyway only moves the failure to the Apply button, where the
  model can no longer revise anything.
- **Config is the blast radius.** `entities` bounds what can be touched,
  `relations` what can be linked; a relation name from a plan is never called
  unless configured. `GuardsDeletion` lets the host veto a delete — asked at
  proposal AND at apply, because an enrollment can arrive in between.

## Testing

```bash
composer install
vendor/bin/phpunit          # 42 tests
```

The suite runs with **laravel-courses absent**, against stand-in models. That is
deliberate: it is the proof that entities really are host-configured rather than
hard-wired. Keep it that way — adding laravel-courses to `require-dev` would
quietly destroy the evidence.

## Publishing

PHP package — Packagist auto-syncs from git tags. Ship = bump → CHANGELOG in the
same commit → tag `vX.Y.Z` → push tag. Then advance the envelope pin.
