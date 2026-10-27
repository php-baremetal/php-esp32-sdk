<!--
Thanks for contributing! Keep a PR focused on one thing. See CONTRIBUTING.md and AI_USAGE.md.
-->

## What and why

<!-- What this changes, and the reason for it. Link any related issue (Fixes #123). -->

## Hardware verification (required)

<!--
This is firmware: a change that compiles is not a change that works. A PR that affects behaviour
must be flashed to a real board and confirmed working. Fill this in, or say why it's exempt
(docs-only / comment-only / test-only changes are the exception).
-->

- **Board(s) tested (board id):**
- **PHP version(s):**
- **What you saw (serial output / observed behaviour):**

```text
paste the relevant serial log here (redact secrets — WiFi passwords, .env values, tokens)
```

## Checklist

- [ ] Verified on real hardware (above), or this is a docs/comment/test-only change.
- [ ] The PHP engine is **not** edited in place — any target change is a build-time patch under `php/<minor>/patches/`.
- [ ] `vendor/bin/phpunit` is green, with a test for this change.
- [ ] Descriptors (`sdk.json`, `boards/`, `php/<minor>/extensions/`, …) updated if this adds or changes an option, board or extension.
- [ ] No build artifacts or generated files committed.
- [ ] I understand and stand behind this code (see the [AI usage policy](https://github.com/php-baremetal/php-esp32-sdk/blob/master/AI_USAGE.md) if an assistant helped write it).
