# Using particle-academy/teachers-aid — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/teachers-aid
php artisan vendor:publish --tag=teachers-aid-config   # config/teachers-aid.php
```

- **Requires:** PHP `^8.4`, Laravel `^13.0`. The service provider
  auto-discovers.
- **You must bind a `ChatDriver`.** There is deliberately no default.
  `PrismChatDriver` ships with the package and needs `particle-academy/prism`;
  any other LLM library is one class implementing `ChatDriver`.
- **Suggested, not required:** `particle-academy/laravel-courses` (the course
  schema the tools are shaped for), and for reading uploads `last-word`
  (.docx), `holy-sheet` (.xlsx) and `dark-slide` (.pptx).
- The React surface is `@particle-academy/teachers-aid-ui`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). Find this package with `search-backend-packages`
(stack `php`) and use `install-instructions` for the exact commands. The UI
half is found with `search-components` (`teachers-aid-chat`, `plan-review`,
`chat-transcript`, `message-composer`).

## API essentials

- **`TeachersAid::respondTo(Message $message, $history)`** returns a turn
  result: `reply`, `hasProposals()`, and `plan` (a `ChangePlan`). Nothing has
  been written at that point.
- **`PlanApplier::apply($plan)`** is the only writer. It runs in one
  transaction, never publishes, resolves forward references (`$c1`) and returns
  `ref => new id`.
- **`ChangePlan`** is JSON-serialisable both ways (`toArray()` /
  `ChangePlan::fromArray()`), so a plan can wait in a session, a queue payload
  or a row between proposing and approving.
- **Tools** (all append to the plan): `propose_curriculum`, `propose_course`,
  `propose_lesson`, `propose_test`, `propose_question`, `propose_update`.
- **A runtime that owns the tool loop** can take `tools($plan)` +
  `systemPrompt()` and make each tool runnable with
  `Drivers\PrismTools::live()`.
- **Config:** `name`, `driver`, `max_steps`, `entities` (entity name => model
  class), `uploads.max_bytes`, `uploads.max_text_chars`.

## Traps

- **Gate the route that invokes `PlanApplier`.** It writes through Eloquent and
  does not consult `laravel-courses`' `AuthorizesCourseAdmin`; authorization is
  the host's job. Put the apply endpoint behind admin middleware, and do not
  fake a `Request` to make the applier check a contract.
- **Config is the blast radius.** An entity absent from `entities` cannot be
  proposed at all; a relation is only called if configured. `GuardsDeletion`
  lets the host veto a delete, asked at proposal and again at apply.
- **A driver must not execute tools or own the loop.** `send()` is one model
  call; the multi-step loop lives in `TeachersAid`. With Prism, the tool
  closures handed to it must stay inert.
- **Unsupported uploads throw `UnsupportedFileException`**, naming the package
  to install. Over-long extractions truncate with a `[TRUNCATED: …]` marker.
- **Tests need no API key:** `tests/Fixtures/ScriptedDriver` shows how to
  replay a scripted conversation through the `ChatDriver` seam.
