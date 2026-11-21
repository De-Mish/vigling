# VIGLING — project context for AI agents

Read this first. It describes what the product is, how the code is organised and the rules the owner (De-Mish) expects every agent to follow. If something here is wrong or out of date, say so and propose a fix instead of silently working around it.

## 1. What the product is

vigling.ru is an online marketplace for beauty and personal-care services in Russia. Clients find specialists ("masters") and book appointments. Masters have a public profile with services, prices, schedule, portfolio, reviews and a map position.

The site has **four sections**, each with its own list page, filters and booking flow:

| Section | Russian name | Joomla component | What it is |
|---|---|---|---|
| Services | Услуги (`/poisk-spetsialistov`) | `com_poisk` | Regular services of a master with price and duration. |
| Promotions | Акции | `com_aktsii` | Limited-quantity special offers ("stock" services with a count and old price). |
| Model search | Поиск моделей | `com_modeli` | A master looks for models for a portfolio, skill practice, demonstrations and similar tasks. |
| Courses | Курсы | `com_kurs` | A master offers training (courses, master classes). |

Bookings are handled by `com_orders` (site) plus the AJAX plugin `plugins/ajax/lkbooking`. The user's personal cabinet (profile, appointments, services, courses, searches) lives in the template overrides under `templates/ryba/html/com_users/profile/`.

### Roles and who may book whom

- **Client**: ordinary registered user. Can book a master in all four sections. Cannot be booked.
- **Master** (Joomla group titled «Мастер»; Super Users and the legacy Author group id 3 count as masters, see `MasterGroupHelper`). Can offer services in all four sections and can also book other masters in all four sections. **Nobody can book a client.**
- Model-search and course offers are **group events**: several users can book the same slot until `capacity` is reached (`#__vigling_search_slots`, `#__vigling_course_slots`, with participants). A master can set an explicit date and time for a model search or course slot. A master can reschedule a group event, and participants answer the proposal (`#__vigling_group_reschedules`, `…_replies`).
- Reviews go both ways: client to master and master to client (`ReviewHelper`).
- Other features: favourites, working schedule and booking pauses per master, push notifications and an in-site inbox, critical notices.

### Catalog: specialty → service → method

This is the core data model and the most common source of confusion.

- **Specialty** = Joomla category (`#__categories`, extension `com_content`, level 2, published, path `uslugi/...`). Repair/sharpening specialties live under `zatochka-remont/...` and are shown in a separate branch of the site.
- **Service** = article in `#__content` (state 1) inside the specialty category.
- **Method** = tag in `#__tags` linked to the article through `#__contentitem_tag_map` (`type_alias = 'com_content.article'`).
- `#__vigling_service_nodes` is a mirror of that tree used for fast lookup. Specialty nodes carry `legacy_id` = category id, group nodes = article id, service nodes = tag id.
- A master's choices are stored in `#__vigling_user_services` and `#__vigling_user_stock_services` (columns `service_node_id`, `legacy_tag_id`, `legacy_cat_id`, `is_active`, `price`; the stock table also has `count_stock`).
- **Filters show the full catalog**, not only what masters have chosen (`PoiskHelper::getServiceHierarchy()` → `loadCatalogServiceHierarchy()`). Result filtering still uses the ids masters actually hold.
- The registration form builds the same catalog directly from categories, content and tags (`templates/ryba/html/com_users/registration/default.php`). Reuse that pattern rather than inventing another.
- The catalog is cached (`CatalogQueryCache`, Redis or file; filter list TTL 3600 s). After a catalog change, clear Joomla's cache.
- The table prefix in production is `joomla_`. Never hard-code it in PHP; use `#__`.

## 2. Tech stack

- **Joomla 6** CMS on **PHP** with **MySQL/MariaDB**. The site is not a framework app: it is Joomla core plus custom extensions and one custom template. Hosting is a conventional shared/VPS setup (config files named `configuration.beget.php` suggest Beget); deploy is manual (see section 4).
- **Custom template** `public_html/templates/ryba` (PHP template overrides in `html/`, CSS in `css/`, jQuery-based JS in `js/`). Main design files: `css/design-tokens.css`, `redesign.css`, `mobile-redesign.css`, `ui-templates.css`.
- **Custom extensions** (namespaces `Viglin\Component\…`, `Joomla\Plugin\User\Vigling\…`):
  - `components/com_poisk`, `com_aktsii`, `com_kurs`, `com_modeli`: list pages (Model, View, Router, map controller each).
  - `components/com_orders`: bookings, reviews, calendars, group rescheduling, pause logic.
  - `components/com_pushnotify`: Firebase Cloud Messaging push (library `kreait/firebase-php` in its own `vendor/`), queue, inbox, preferences, cron scripts in `com_pushnotify/cron`.
  - `plugins/user/vigling`: the biggest plugin: master group logic, services/courses/searches services, catalog helpers and caches, schedule, image upload, site guard.
  - `plugins/ajax/lkbooking` (booking actions) and `plugins/ajax/quickauth` (quick login/registration, favourites).
  - `plugins/system/viglingrecaptcha`, `emailverification`, `pushnotifybooking`.
  - `modules/mod_specialists` and the admin counterparts in `administrator/components/` (`com_poisk`, `com_aktsii`, `com_kurs`, `com_modeli`, `com_orders`, `com_pushnotify`, `com_viglingservices`).
- **Maps**: Yandex Maps JS API 2.1 (`templates/ryba/js/list-map.js`).
- **PWA**: `manifest.json`, `pwa_maker_service_worker.js`, `templates/ryba/offline.php`, install prompt in the template. The site is installable as an app.
- **Third-party UI libs**: jQuery, Chosen (selects), jquery.datetimepicker, Slick, Dropzone.
- No machine-learning or neural-network features exist in the code today.

## 3. Security expectations

Security and data protection matter a lot to the owner. For every change:

- State-changing requests must check the Joomla CSRF token (`Session::checkToken`) and the caller's permissions server-side. Never trust hidden fields, ids from the browser or client-side role checks (for example "client cannot be booked" must be enforced on the server).
- Use Joomla's query builder with `quote`/`quoteName`/`whereIn`/bound ints. No string-built SQL from request data.
- Escape all output (`htmlspecialchars`, `Text::_`), including values echoed into inline JS (use `json_encode` with safe flags).
- Uploads go through `ImageUploadHelper` (type, size, re-encode checks).
- Existing protections to keep working: reCAPTCHA on forms, e-mail verification of new accounts, booking rate limit and feature flags (`SiteGuardHelper`: `booking_create`, `booking_cancel`, …), booking audit table, security headers in `.htaccess`, privacy consent and user-agreement pages.
- Personal data (phones, addresses, e-mail) must not be logged or exposed to users who do not need it.
- Never commit secrets: `configuration.php`, `public_html/configuration/`, Firebase credentials, `.env`, keys and dumps are git-ignored on purpose. **Do not write passwords, even test ones, into the repository.** Ask the owner for test accounts when needed.

## 4. Working agreement (standing rules)

1. **Change only what was asked.** If something else looks broken or risky, report it first and wait for approval before touching it.
2. **Language**: all user-facing site text is Russian. Talk to the owner in English unless they explicitly ask for Russian.
3. **Do not rename Joomla menu items.**
4. **Never push to `main`.** Work on a `cursor/<topic>-263b` branch and open a PR. Do not merge it.
5. **Deploy is manual** (files are uploaded by hand). After every PR list the exact files that changed, with paths relative to the repo, so they can be uploaded. Mention anything that needs a cache clear or a database step.
6. Database changes are delivered as SQL files under `migrations/<topic>/` (force-add them: `*.sql` is git-ignored). Include a read-only preflight and make the apply script safe to run once. The owner runs them in phpMyAdmin; keep them paste-ready (no comment lines).
7. Images and archives are git-ignored (except `public_html/icons/`). Do not expect uploaded media in the repo.
8. The agent environment has no PHP CLI, so `php -l` cannot run. Re-read PHP edits carefully for syntax, check brace balance, and say clearly that the code was not executed. Headless Chrome and `curl` are available for checking the live site.
9. Match the surrounding code style (tabs in most PHP files, Joomla coding patterns, no narrating comments).
10. Prefer small, reviewable PRs, one logical change each.

## 5. Design and UX

- Brand look: gold `#f7cc53` primary buttons (pill shape), ink `#111`, rounded 12px cards, light shadows. Tokens live in `templates/ryba/css/design-tokens.css`; extend them instead of adding one-off colours.
- Mobile first: most users arrive from phones, many through the installed PWA. Check every UI change at phone width.
- The profile cabinet colour-codes the four sections (services, promotions, courses, model search); appointment cards use matching tints (`appointments-card--service/--course/--search`).
- Reference material for design decisions is in the `Website design tips/` folder (colour, composition, layout, typography, design principles).

## 6. Where to look first

| Task | Start here |
|---|---|
| Filters / list pages | `components/com_<section>/src/Model/ListModel.php`, `View/List/HtmlView.php`, `tmpl/list/default.php`, `com_poisk/src/Helper/PoiskHelper.php` |
| Catalog and master services | `plugins/user/vigling/src/Helper/JsnDecodeHelper.php`, `Service/UserServicesService.php`, `migrations/service-structure/` |
| Booking logic | `components/com_orders/src/Controller/OrdersController.php`, `Helper/AppointmentsHelper.php`, `plugins/ajax/lkbooking/src/Extension/Lkbooking.php` |
| Courses / model searches | `plugins/user/vigling/src/Service/UserCoursesService.php`, `UserSearchesService.php` |
| Cabinet and profile UI | `templates/ryba/html/com_users/profile/` |
| Registration | `templates/ryba/html/com_users/registration/default.php` |
| Push notifications | `components/com_pushnotify/src/Helper/FcmHelper.php`, `cron/` |
| Styling | `templates/ryba/css/redesign.css`, `mobile-redesign.css`, `design-tokens.css` |

Note: `JsnDecodeHelper` and some column names (`legacy_*`, `jsn_*`) come from an earlier migration off the JSN extension. The `legacy` naming is historical, not a sign of dead code.

## 7. Ideas the owner may want later (not implemented)

Listed so agents understand the direction; do not build them unless asked.

- Smarter search ranking and "recommended for you" using booking history and reviews (a classic recommender is enough before any neural network).
- Photo-based help for masters, such as portfolio tagging or quality checks.
- Automatic moderation of reviews and portfolio images.
- Appointment reminders and no-show prediction on top of the existing push queue.

## 8. Known open items (November 2026)

- "Фут-массаж (массаж стоп)" was not placed in the new service structure because its path has no group level. Waiting for the owner's decision.
- The service-structure migration created 186 migrated-method tags where 187 were expected; one was not investigated.
