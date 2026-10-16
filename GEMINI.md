# BagooPH Agent Reference

[AGENTS.md](AGENTS.md) is the canonical repository instruction file. Read it
before work, including its local task-management routing, Git permissions,
database isolation and verification rules. This reference avoids maintaining a
second conflicting copy of those instructions.

## Current architecture

- Laravel 13/PHP backend, React 18/TypeScript web pages, Inertia.js 2, Tailwind CSS
  3, Vite 6 and PostgreSQL 16; Docker Compose runs Nginx and PHP-FPM.
- [TECH_STACK.md](docs/TECH_STACK.md) verifies resolved versions and supporting
  tools. [ARCHITECTURE.md](docs/ARCHITECTURE.md) explains the monolith, web/API
  boundaries, services and deployment. [The presentation guide](docs/TECH_STACK_PRESENTATION.md)
  explains why the choices fit BagooPH.
- Five stored roles: buyer, seller, courier, logistics and admin. Pickup/delivery
  are courier phases; company/hub responsibilities stay inside logistics.
- Keep approval, activity, role, company placement, duty and ownership separate.
  Buyer personal access and checkout eligibility follow the current buyer guide.
- Preserve one reviewed shop per seller, the required Mother Hub route, buyer-only
  completion and separate COD reconciliation/settlement. Use the normative flow
  documents rather than historical examples.

## Work and documentation

- Work in this repository; preserve unrelated changes. Local staging and
  descriptive commits are allowed. Only the user pushes.
- Use isolated SQLite `:memory:` for ordinary tests; never reset the development
  or production PostgreSQL database for testing.
- Follow each portal's existing design. The shared identity uses `#E00D42`,
  Plus Jakarta Sans, Philippine pesos and Shopping Bag wording. The rider's local
  8px corners and blush surfaces are defined in AGENTS and the rider/style guides.
- Check actual seeders for demo identities/passwords; use [Getting started](GETTING_STARTED.md)
  for their documented source defaults. Do not restore retired demo shortcuts,
  role conversion or simulator routes.
- Use [docs/README.md](docs/README.md) to route documentation and
  [CORE_FLOW_ROADMAP.md](docs/CORE_FLOW_ROADMAP.md) for changing implementation
  evidence. Historical/source documents do not override current contracts.
- Keep private environment values, real server details and local task-workflow
  contents out of tracked files. Do not introduce new services through a doc edit.
