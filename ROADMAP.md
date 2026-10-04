# HashOver 2 roadmap

Goal: a modern, well-tested, secure and accessible (WCAG 2.1 AA) comment
system for PHP websites. Branch: `v2-modernization`. The individual
improvement ideas are listed in `IMPROVEMENTS.md`; the numbers below refer
to that file.

## Phase 1 — Safety net ✅

- Composer project, PSR-4 autoloading (`HashOver\` → `src/`)
- PHPUnit 11: unit tests for the escaping, validation and crypto helpers;
  black-box integration tests that run HashOver under PHP's built-in server
  and replay the security checks from the audit (83 tests)
- Integration tests fail on any PHP warning, notice or deprecation
- PHPStan 2: level 10 for new code (`src/`, `tests/`); a transitional
  level-5 configuration for the legacy `scripts/` (removed in phase 2)
- GitHub Actions CI on PHP 8.2, 8.3, 8.4 and 8.5
- Found and fixed: anchored validation regexes accepted a trailing newline

## Phase 2 — Core (#12, #14, #15, #17, #4, #26)

Classes for configuration, authentication, crypto, mailing and comment
storage, replacing the globals. Storage interface with the XML format and
SQLite, plus a migration command. Exceptions instead of `exit()`.

## Phase 3 — Rendering (#13, #7, #20, #21, #23, #24)

One server-rendered, progressively enhanced UI: works without JavaScript,
JavaScript only enhances it through `fetch()`. No inline handlers, so host
pages can use a strict Content-Security-Policy.

## Phase 4 — Security features (#1, #2, #5, #6, #8, #9, #10, #11, #22)

Rate limiting, admin session with logout, CSRF tokens, moderation queue,
privacy defaults, robust HTML sanitizing.

## Phase 5 — Accessibility (#19)

Semantic forms and buttons, focus management, live regions, contrast,
followed by a full WCAG 2.1 AA audit.

## Phase 6 — Release (#3, #16, #18, #25, #27)

Documentation, upgrade guide, release zip with vendor/ included, 2.0.0 tag.

## Open decisions

| Question | Current assumption |
|---|---|
| Keep the 1.x embed snippets (`<script src=…/comments.php>`, PHP include) working? | Yes, with a compatibility layer |
| Composer dependencies allowed? | Yes; release zip ships `vendor/` for hosts without Composer |
| Third-party services (Gravatar, stopforumspam) | Opt-in, off by default |
| Minimum PHP version | 8.2 |
