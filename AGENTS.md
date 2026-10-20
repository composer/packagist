# AGENTS.md

Packagist is the package repository hosting PHP packages; its audience is PHP developers.
It is a Symfony app on up to date versions of everything (PHP, MySQL, Redis, dependencies).
Check `composer.json` for exact versions.

## File locations

### Styling
- Main CSS: `css/app.scss` (compiled to `web/build/app.css`)
- Uses Bootstrap 5 and Bootstrap Icons, imported at the top of `css/app.scss` from
  `node_modules/bootstrap/dist/css/bootstrap.min.css` and
  `node_modules/bootstrap-icons/font/bootstrap-icons.css`
- Since the compiled CSS is imported rather than the sources, customisation goes through the
  `:root` CSS variable overrides in `css/app.scss`, not SCSS variables

### Templates
- API documentation: `templates/api_doc/index.html.twig`
- Layout base: `templates/layout.html.twig`

### Controllers
- API endpoints: `src/Controller/ApiController.php`
- Package routes: `src/Controller/PackageController.php`
- Web routes: `src/Controller/WebController.php`
- Explore routes: `src/Controller/ExploreController.php`

## API authentication
- `findUser()` in ApiController handles auth with `ApiType::Safe` vs `ApiType::Unsafe`
- Bearer token format: `Authorization: Bearer username:apiToken`
- Two token types: main (`apiToken`) and safe (`safeApiToken`)
- Bearer token takes precedence over query parameters

## Conventions

- Prefer modern PHP features:
  - Constructor property promotion with public properties instead of getters/setters
  - Virtual properties with accessor functions for computed values (not readonly if using get{})
  - Meaningful property names (e.g. `contents` instead of generic names like `data`)
- Use PHP attributes for framework metadata (`#[Route]`, `#[IsGranted]`, `#[Assert\...]`,
  `#[AsCommand]`, `#[AsEventListener]`, ...). Static template/redirect routes live in
  `config/routes.yaml`.
- Rely on autowiring and autoconfiguration; reach for `#[Autowire]`/`#[Target]` before a
  `config/services.yaml` definition. Don't mark a service `readonly` if it is (or may become)
  `lazy: true`.
- Controllers extend `App\Controller\Controller`, stay thin, and delegate to services.
- Async work goes through the Redis job queue (`App\Service\QueueWorker` and its job
  workers), not Messenger, which is not installed.
- Mutual exclusion goes through `App\Service\Locker` (MySQL `GET_LOCK`).
- Comment only what a reader would otherwise get wrong (a constraint, a race, a decision that
  looks like a bug), in a line or two. No comments on routine guards, clamps or redirects; put
  that reasoning in the commit message instead.
- Keep API responses well-shaped (sorting, normalisation, defaults) even if the current client
  would cope without it.

## Design rules
- Who did something is recorded in the audit log (`AuditRecord`), not in `deletedBy`/`updatedBy`
  style columns on the entity. An enum for the kind of action (admin vs maintainer) is fine.
- Conditions that can re-fire on every crawl (blocked tag changes, repeated update failures)
  must audit/mail/log once per actual change: store a "last seen" key, act only when it changes,
  clear it on resolution. See `Package::isUpdateFailureNotified()`.
- To signal "needs reprocessing", bump a monotonic `...RequestedAt` timestamp instead of nulling
  the worker's done-at column, so requests landing mid-run aren't lost. Staleness is
  `doneAt IS NULL OR requestedAt >= doneAt`. See `Package::markForDump()`/`isDumpRequested()`.
- Stable versions are immutable: never hard-delete one, so its `(package, version)` slot can't be
  reused with different content (enforced centrally in `VersionRepository::remove()`). Soft-delete
  and hiding are fine. Keep every existing safety check on stable/frozen/immutability paths even
  where a later check would catch the same case; prefer one central guard over per-caller checks.
- Decorative backend reads (stat counters etc.) on hot routes must not 500 the page when Redis
  or the DB is down. Catch the base exception (`\Predis\PredisException`,
  `\Doctrine\DBAL\Exception`), not a connection subclass.
- When a request degrades to a cheaper fallback, keep the requested state in the UI and links,
  show a banner via a separate flag, and keep the working mode reachable as a link.
- `.txt.twig` emails are never escaped, whatever `{% autoescape %}` says. Only interpolate
  constants, `url()` output and validated identifiers; keep user/admin free text out of mails
  and subjects.
- Never commit anti-abuse data (spam model weights, thresholds) that would let spammers game
  detection; it is deployed out of band (`SPAM_MODEL_FILE`).

## Database
- No Doctrine migrations: schema changes are raw SQL files added to `migrations/`, named
  `YYYY_MM_description.sql` with a comment header explaining what and why. Never apply them
  yourself.
- Write datetimes from PHP (bound parameters), not with SQL `NOW()`: PHP runs on UTC and the
  MySQL session timezone isn't guaranteed to match, so mixing the two breaks comparisons.
- `download.package_id` is nullable and write-once; filter stats queries on
  `package_version.package_id`.
- Ad-hoc SQL using user variables needs `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;` first, as
  the tables are `utf8mb4_unicode_ci` and MySQL 8 connections default to `utf8mb4_0900_ai_ci`.
- Dev data is far smaller and skewed compared to prod, so don't size queries or trust `EXPLAIN`
  plans from it; use it to check that SQL is valid and the intended index is picked.

## Workflow
- Run the app with `symfony serve -d` (see README.md), commands with `bin/console`.
- When something fails, read `var/log/dev.log` and the web profiler (`/_profiler`) first.
- `.env*` files are committed and hold defaults only; real secrets go in `.env.local`.
- Run `npm run build` (or `npm run dev` to watch) to build frontend files.
- Look things up instead of guessing: `bin/console debug:router`, `debug:container`,
  `debug:autowiring <name>`, `debug:config <bundle>`, `lint:container`, and the source under
  `vendor/`.

## Testing
- Run `composer phpstan` for PHPStan and `composer test -- --filter <test suite>` to run tests
- Tests that need the app extend `App\Tests\IntegrationTestCase`; others extend
  `KernelTestCase` or plain `TestCase`.
- Avoid the "composer" vendor name for packages/repos in tests, as validation blocks it
- Behaviour changes need PHPUnit tests, including negative cases; manual checks are a sanity
  pass after the suite is green, not a substitute.
- Tests don't hit the network (`Fixtures::createPackage()` sets the repository by reflection for
  that reason). If a path can only be covered by a slow or networked test, say so and leave the
  call to the maintainers.
- Use `createStub()` for doubles without expectations rather than `createMock()` plus
  `#[AllowMockObjectsWithoutExpectations]`.
- MySQL optimizer hints (`/*+ ... */`) that are invalid only produce a warning, so assert
  `SHOW WARNINGS` is empty to prove a hint applies.

## Pull requests
- One PR per independent improvement; related follow-up fixes to the same code stay in one PR,
  with one commit per fix where that helps attribution.
- Write commit messages and PR descriptions for the project's audience (PHP developers): describe
  the user-facing change, not internal operational context.
- Raise performance concerns in review only with concrete evidence; otherwise ship and measure.

## Code style
- `.php-cs-fixer.php` (`@Symfony`, `@PER-CS` and the PHP 8.4/PHPUnit migration sets).
