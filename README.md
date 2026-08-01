# particle-academy/teachers-aid

Teachers Aid Chat (**TAC**) — an authoring agent that reads course material and **proposes** curriculum, course and test changes for a human to review.

Two things make this package what it is:

- **It is LLM-library agnostic.** The agent, its tools and the plan model import no LLM library at all. One interface, `ChatDriver`, is where Prism, the Laravel AI SDK, the Vercel AI SDK or your own client plugs in.
- **It cannot write.** The tools record proposals into a `ChangePlan`. Only `PlanApplier` touches the database, and only when a human hands it an approved plan.

## Install

```sh
composer require particle-academy/teachers-aid
```

The service provider auto-discovers. Publish the config to change the agent's name, its blast radius, or the upload limits:

```sh
php artisan vendor:publish --tag=teachers-aid-config   # config/teachers-aid.php
```

**You must bind a driver.** There is deliberately no default — silently talking to the wrong provider is expensive in both senses of the word.

```php
// AppServiceProvider::register()
use ParticleAcademy\TeachersAid\Contracts\ChatDriver;
use ParticleAcademy\TeachersAid\Drivers\PrismChatDriver;

$this->app->bind(ChatDriver::class, fn () => new PrismChatDriver(
    provider: config('teachers-aid.drivers.prism.provider'),
    model: config('teachers-aid.drivers.prism.model'),
));
```

`PrismChatDriver` ships with the package and needs `particle-academy/prism`. Any other library is one class — see [Writing a driver](#writing-a-driver).

## Use

```php
use ParticleAcademy\TeachersAid\Agent\TeachersAid;
use ParticleAcademy\TeachersAid\Chat\Message;
use ParticleAcademy\TeachersAid\Plan\PlanApplier;

$result = app(TeachersAid::class)->respondTo(
    Message::user('Build a course on situational awareness from this handbook.', [$attachment]),
    $history,
);

$result->reply;          // what to show the teacher
$result->hasProposals(); // did it draft anything?
$result->plan->toArray() // the diff to render for review
```

Nothing has been written at this point. When the teacher approves:

```php
$refs = app(PlanApplier::class)->apply($plan);  // ref => new id
```

`$result->plan` is JSON-serialisable both ways (`ChangePlan::fromArray()`), so the plan can sit in a session, a queue payload or a database row between proposing and approving.

## Why propose-then-apply is structural

The agent holds no repository, no model and no connection. There is no code path from a tool call to a write — so a confused model, **or a prompt injection hidden inside an uploaded file**, still cannot change anything. The approval step is enforced by the object graph, not by the system prompt.

> **Gate the route that invokes `PlanApplier`.** The applier writes through Eloquent, the same layer `EnrollmentService` and `CertificateService` write at — so it deliberately does **not** consult `laravel-courses`' `AuthorizesCourseAdmin`, which gates the HTTP layer. That is the correct layering, not a hole: a host calling these from a console command, a queue job or a seeder has already decided. But it does mean **authorization is the host's job here**. Put the apply endpoint behind your admin middleware. GuardCard's sits behind `['auth', 'admin']`.
>
> Don't hand the applier a faked `Request` to make it consult the contract — a check that looks like enforcement but isn't is worse than no check.

`PlanApplier` adds three guarantees:

| Property | Why |
|---|---|
| **All or nothing** | One transaction. A half-built course is worse than no course. |
| **Never publishes** | `is_published` is forced `false` on create and stripped on update, whatever the plan says — on the entities that have the column. Lessons, questions and options do not, and forcing it on them would fail the insert. A human publishes; approving a draft and putting it in front of learners are different decisions. |
| **Forward references** | `$course1` in a later operation resolves to the id created earlier in the same plan. |

Forward references are what let one turn produce a whole course:

```php
$plan->add(ChangeOperation::create('course', ['title' => 'Patrol Basics'], ref: 'c1'));
$plan->add(ChangeOperation::create('lesson', ['course_id' => '$c1', 'title' => 'Lesson one']));
```

An unresolvable reference fails the entire plan rather than orphaning a row.

## Writing a driver

Implement two methods. The driver does three translations and **must not execute tools** — it reports what the model asked for, and the agent decides.

```php
interface ChatDriver
{
    /** @param list<Message> $messages  @param list<ToolDefinition> $tools */
    public function send(array $messages, array $tools = []): ChatResponse;

    /** @return list<string> MIME types the model reads as raw bytes. [] means text-only. */
    public function nativeMimeTypes(): array;
}
```

**The multi-step tool loop lives in the agent, not the driver.** Every LLM library has its own idea of agentic looping and step limits; if each driver brought its own, TAC would behave differently depending on what was underneath. `send()` is one model call.

One wrinkle worth knowing if you write your own: some libraries own tool execution outright. Prism runs its tool closures on every `tool_use` response *before* it checks the step limit, so `PrismChatDriver` hands it deliberately inert closures and reads the requested calls off the response instead. Putting real behaviour in those closures would execute tools inside the driver and silently defeat the approval guarantee.

## Files

Attachments go through `AttachmentPipeline`, which asks **the driver** what it reads natively rather than consulting a hard-coded list. The same PDF is passed through as bytes on a multimodal driver and extracted to text on a text-only one, with no change at the call site.

| Format | Handling |
|---|---|
| PDF, PNG, JPEG, … | Native, if `nativeMimeTypes()` says so |
| `.docx` | Extracted — needs [`last-word`](https://github.com/Particle-Academy/last-word) |
| `.xlsx` | Extracted — needs [`holy-sheet`](https://github.com/Particle-Academy/holy-sheet) |
| `.pptx` | Extracted — needs [`dark-slide`](https://github.com/Particle-Academy/dark-slide) |
| `.csv`, `.txt` | Extracted, no extra package |
| anything else | `UnsupportedFileException`, naming the package to install |

Two details worth knowing:

- **Over-long extractions truncate loudly**, with a `[TRUNCATED: …]` marker in the text. Silent truncation would have the model build a course from material it never saw and sound confident about it.
- **The extension is a fallback for a useless MIME type.** Browsers send `application/octet-stream` for a CSV often enough to matter.

Adding a format is a new `ExtractsText` implementation registered with the pipeline. The agent never changes.

## Tools

Six, all of which only append to the plan:

`propose_curriculum` · `propose_course` · `propose_lesson` · `propose_test` · `propose_question` · `propose_update`

`propose_question` emits the question **and** its options as linked operations in one call, so a multiple-choice question is never proposed half-formed.

An unknown tool name is reported back to the model rather than thrown — it is recoverable, and models usually correct themselves on the next step.

## Configure

```php
'name'      => env('TEACHERS_AID_NAME', 'TAC'),   // appears in the system prompt and chat header
'driver'    => env('TEACHERS_AID_DRIVER', 'prism'),
'max_steps' => (int) env('TEACHERS_AID_MAX_STEPS', 8),

// Entity name (as the model refers to it) => model class.
// Anything absent cannot be proposed at all, so this doubles as the blast radius.
'entities' => [
    'curriculum'      => Curriculum::class,
    'course'          => Course::class,
    'lesson'          => Lesson::class,
    'test'            => Test::class,
    'question'        => Question::class,
    'question_option' => QuestionOption::class,
],

'uploads' => [
    'max_bytes'      => 20 * 1024 * 1024,
    'max_text_chars' => 200_000,
],
```

`laravel-courses` is a **suggestion, not a requirement**. Entities are host-configured, so any Eloquent models with the right columns work — the test suite proves it by running against its own stand-in models with `laravel-courses` absent entirely.

## Testing

```sh
composer install
./vendor/bin/phpunit
```

The suite needs no API key, no provider and no network: `tests/Fixtures/ScriptedDriver` replays a scripted conversation. That it can exist at all is the point of the `ChatDriver` seam.

## License

MIT.
