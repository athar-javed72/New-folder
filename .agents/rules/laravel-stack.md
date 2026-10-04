# Laravel 12 + PostgreSQL Project Rules

1. **Migrations are immutable once committed.** Fix schema problems with a new migration, never by editing an old one. Provided migrations `2026_10_02_000001..000012` are frozen.
2. **Verification gate (replaces generic type/build checks):** `php -l` on changed files, `./vendor/bin/pint --test`, `./vendor/bin/pest`, and Larastan once installed. All must pass before a task is marked complete.
3. **Database enforces integrity.** Do not replace a database constraint (composite FK, CHECK, exclusion, trigger) with application code; add application checks only for friendly error messages.
4. **Money** is integer paisa. No float arithmetic for money or leave balances. Keep `declare(strict_types=1);`.
5. **Tenancy:** every tenant table carries `organization_id`; every query on tenant data must be scoped by it.
6. **Policies/presets:** never change preset content in place; a change needs a new version number.
7. **Docs:** check Laravel 12 behaviour with Context7 before using any API you are not sure about. Do not guess.
8. **Secrets:** `.env` is never committed; never print credentials in logs or progress.txt.
