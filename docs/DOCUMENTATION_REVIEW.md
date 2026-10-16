# Architecture and Stack Documentation Review

Review date: October 16, 2026. Starting source: `6d2ff6a` on merged `main`.
The working branch is `docs/verified-architecture-stack`. The user requested a
source-verified explanation of the current technology stack and documentation
suitable for presenting the project. All edits stay in this web repository.

## What to read

- [Technology presentation](TECH_STACK_PRESENTATION.md): opening explanation,
  six-part outline, rationale, examples and questions to prepare for.
- [Verified stack](TECH_STACK.md): resolved versions, runtime targets,
  supporting libraries, source links and external boundaries.
- [Architecture](ARCHITECTURE.md): monolith, Inertia web requests, native JSON
  entry points, access, domain services, transactions and deployment.
- [Schema guide](SCHEMA.md): relationships, selected actual fields and all
  98 table names from the isolated migration inspection.

## Review scope and authority

The review inventories all tracked Markdown documents and scans their current
architecture/stack claims, including root setup and agent references. Documents
without changed claims remain in place. A repository-wide link/heading check
covers that complete inventory plus the new guides. Source verification focuses
on the documented technologies, request boundaries, routes, schema and commands;
it is not a new functional/security certification of every role or feature.

Historical roadmap entries and `PROGRESS.md` retain their dated evidence.
`ERP_COMPONENTS_SPECIFICATION.md` and the original PDFs remain original sources.
Delivery plans remain intended contracts, not claims that every planned feature
is already released. The documentation map and dated roadmap continue to route
business authority and implementation evidence.

## Corrections made

| Previous problem | Corrected reference |
|---|---|
| Current files called the backend Laravel 11/12 and the frontend React 18/19. | Locks and local installed metadata identify Laravel 13.32.0 and React 18.3.1. The stack guide distinguishes allowed ranges, resolved packages, configured image tags and unverified server state. |
| Inertia was described as avoiding APIs without mentioning the existing rider API. | The architecture explains Inertia page responses and session-backed web requests alongside `/api/v1` JSON endpoints using the same domain rules. |
| Root context treated courier phases as separate roles, permitted role conversion or implied a single sorting-center shortcut. | Current context uses five fixed account roles, company/facility responsibilities and the required Bayan/Mother/Bayan custody chain. |
| The README/setup advertised an old password, a nonexistent seeded courier identity and retired one-click login shortcuts. | The getting-started guide uses the actual seed source accounts and `Password1234`; setup and destructive local reset are distinguished from ordinary deployment. |
| Project/testing references described simulator routes, automatic distribution at delivery and a universal rider fee. | Current references use actor-owned routes, buyer completion, counted COD reconciliation and separately evidenced seller payment. No fixed rider fee or automatic banking integration is claimed. |
| The schema listed outdated columns, fictional/old table names and only a small fraction of current persistence. | The guide verifies selected current columns and inventories all 98 migrated tables. Variant JSON, `middle_name`, `sex`, current ledgers and retained source records are explained explicitly. |
| Agent references disagreed about Git, approval authority, roles, styling and seed defaults. | AGENTS records the current stack; both Gemini entry points defer to the same canonical instructions and portal-specific guides. |
| The rider account contract still said sex was optional and advertised `sex_required=false`. | The contract now matches the current shared validator and registration-options controller: sex is required; registration postal code remains optional with a four-digit limit. |
| Background configuration could be mistaken for operating services. | Deployment and architecture docs list the current schedules and explain that Compose starts no scheduler or queue worker. Database session/cache/queue defaults and unused provider templates are distinguished from deployed services. |

The review also explains optional existing mail, sign-in, address, map, font and
text-assistant integrations without claiming their live availability or adding
new services.

## Evidence and verification

The final inventory contains **106 Markdown files**. **Nineteen documents** are
updated or added. The checks inspect **1,167 local links/heading targets** and
**122 source-path references** in changed documents. External links are counted
separately; only the official technical references used for stack explanations
were opened, not every external historical/source citation.

- Parsed `composer.json`, `composer.lock`, `package.json` and `package-lock.json`.
  Read-only local runtime metadata confirms PHP 8.4.24, Laravel 13.32.0, Inertia
  Laravel 2.0.25 and Sanctum 4.3.3. Twelve inspected installed frontend packages
  match their locked versions. Composer manifest/lock validation succeeds.
- Inspected Dockerfile/Compose/Nginx, bootstrap/routes, TypeScript/Vite/Tailwind,
  framework defaults, schedules, seeders and the documented backend services.
- Listed Laravel's application routes in an isolated testing configuration,
  without operational HTTP requests or account/custody mutations. Documented
  endpoint examples are checked against the resulting registrations.
- Booted a disposable SQLite `:memory:` application, refused other database
  settings, ran migrations and inspected tables/columns/indexes/foreign keys.
  The schema inventory and selected documented columns are matched to that
  output. No development or production database was touched.
- Checked local document/source links, heading targets, changed source paths,
  code-fence structure, current-stack claims, private-content exclusions and
  Git whitespace. Official technical references in the stack guide support
  tool descriptions; local source determines Bagoo's actual dependencies/use.

This is a documentation change. It does not change dependencies, PHP/React
behaviour, routes, migrations, seed data or deployment configuration. Functional
suites and the asset build were not rerun to validate unchanged runtime code.
The separate Flutter repository was not inspected or changed. Its SDK/package
versions and device behaviour are not certified by the web/API explanation.

No Azure connection, deployment, real mailbox/provider call, visible browser,
screenshot or PostgreSQL test was performed. No fresh-clone setup or Mermaid
browser rendering is claimed; commands, diagram structure and references were
checked against their executable sources. Live HTTPS, firewall, background
processes, backups and the running Azure revision remain operator evidence.

For the lasting delivery record and any remaining implementation mismatch, use
[CORE_FLOW_ROADMAP.md](CORE_FLOW_ROADMAP.md). Keeping archived claims dated is
part of an accurate review, not a claim that they describe the current system.
