# BagooPH Technology Presentation

Use this guide to explain the implemented web system in plain language. The
[verified stack](TECH_STACK.md) contains exact locked versions and source links;
[architecture](ARCHITECTURE.md) explains the technical boundaries. This guide
does not certify the live Azure deployment or the separate Flutter application.

## Opening explanation

> BagooPH is an ecommerce and logistics system connecting buyers, sellers,
> riders, logistics staff and Platform Admin. The web application uses Laravel
> 13 and PHP for backend processing, React 18 with TypeScript for its interface,
> Inertia.js to connect those pages to Laravel, Tailwind CSS for styling and
> PostgreSQL 16 for persistent data. Docker Compose runs the application
> services, and Azure is the hosting target. The same Laravel backend also
> exposes a versioned JSON API for the separate rider application.

## A six-part presentation

| Part | Explain | Concrete BagooPH example |
|---|---|---|
| 1. Project purpose | Several roles cooperate around one recorded purchase. | A buyer checks out; a seller prepares; riders and hubs record custody; the buyer confirms receipt; cash and seller payment are reconciled separately. |
| 2. Frontend | React builds the interface, TypeScript checks its shapes and Tailwind supplies styling/responsive utilities. | A checkout form, the seller order list and an admin review dialog use reusable components but retain their own portal layout. |
| 3. Backend | Laravel validates inputs and enforces permission and business rules. | The server checks the real product price, available stock and buyer approval rather than accepting a browser-supplied total. |
| 4. Connection | Inertia returns a React page and its allowed data from a Laravel controller. Native clients use JSON endpoints. | The web purchases page receives Inertia props; a rider's Home request uses `/api/v1/rider/home`. |
| 5. Data and consistency | PostgreSQL stores relationships and transaction evidence. | An order refers to its buyer, items and parcel; a failed checkout rolls back the selected stock/order writes. |
| 6. Deployment and verification | Docker defines the services, Vite builds assets and Azure hosts the deployment. | Nginx forwards PHP requests to PHP-FPM; PostgreSQL retains data in its volume. Tests, builds and actual deployed checks establish different kinds of evidence. |

## Explain why each main choice fits

- **Laravel/PHP:** account approval, stock, custody, cash and settlement need a
  consistent set of server rules. Laravel supplies routing, middleware,
  validation, migrations and database tools; Bagoo's own services implement the
  business decisions.
- **React:** the portals need interactive forms, dialogs, selections and lists.
  Components let the project reuse those controls while keeping each role's
  interface coherent.
- **TypeScript:** the frontend exchanges structured page data and action inputs.
  Type checking helps identify mismatches before an asset build succeeds.
- **Inertia.js:** the web UI can use React while Laravel keeps routing, sessions
  and page-data ownership. It reduces the need to maintain a separate general
  page API and frontend router for this web application. Laravel's official
  [frontend guide](https://laravel.com/framework/docs/13.x/frontend) describes
  this integration model.
- **Tailwind CSS:** existing utility classes and theme values support consistent
  spacing, responsive layouts, typography and Bagoo's accent colour.
- **PostgreSQL:** ecommerce records have relationships, ownership and transaction
  requirements. Database constraints and transactional service code help keep
  stock, parcel custody and money evidence consistent.
- **Vite:** turns the frontend source into deployable browser assets and supports
  the local development asset server. It is a build/development tool.
- **Docker Compose:** describes the PHP-FPM, Nginx and database services together,
  so development and deployment can follow the same service arrangement.
- **Azure VM:** supplies the machine hosting that arrangement. The application
  still needs correct environment settings, HTTPS, backups and background
  processes managed for that deployment.

Present these as reasons the existing stack fits the project. There are no
project benchmarks proving it is faster or cheaper than every alternative.

## Explain one web request

1. The buyer submits checkout from a React page.
2. The request reaches Laravel through Nginx and PHP-FPM.
3. Middleware and services check the current account, ownership, submitted
   fields, real prices, stock, offers and the complete supported hub route.
4. A database transaction writes the per-shop orders, parcel records and stock
   changes together. Required evidence remains bound to the transaction.
5. After success, Laravel redirects to the existing purchases workspace.
   Inertia updates the React page using the server's saved result.

Later authenticated actions record seller preparation, actual rider/hub
handoffs, delivery and buyer confirmation. Recorded delivery, buyer completion,
cash reconciliation and evidenced seller payment are separate events. This is
why seeing `delivered` does not mean the seller has already received money.

## Questions to prepare for

### What kinds of technologies are these?

| Term | BagooPH example |
|---|---|
| Programming language | PHP on the backend; TypeScript compiled into browser JavaScript |
| Backend framework | Laravel |
| UI library | React |
| Web integration/protocol | Inertia.js |
| CSS framework | Tailwind CSS |
| Database management system | PostgreSQL |
| Object-relational mapper | Eloquent, included with Laravel |
| Web server and PHP execution | Nginx and PHP-FPM |
| Build tooling | Node.js/npm, TypeScript and Vite |
| Container environment and hosting | Docker Compose and Azure VM |
| HTTP interface | The versioned rider JSON API |

### What is the architecture called?

A **modular monolith with a layered backend**: one Laravel application and
shared database, with business services grouped around orders, logistics,
governance, cash, vouchers and other domains. The web frontend uses Inertia.
The native rider API is another entry point to the same backend rules.

### Are React and Laravel separate applications?

They have separate responsibilities. In this repository, their web source and
deployment are integrated. React runs in the browser; Laravel executes on the
server. The rider client is separate and communicates over the JSON API.

### Is Inertia another API server or an extra network middleman?

Inertia is a pair of client/server adapters and a page-response protocol inside
the existing web application. A Laravel controller returns a component name and
allowed props; React renders them. It does not add another deployed service.

### Do we use REST or RESTful APIs?

The rider interface is a **versioned REST-style HTTP JSON API**. It uses methods
such as GET, POST, PATCH and DELETE, bearer authentication and HTTP error codes.
Some operations are action endpoints, such as claiming a pickup job. The whole
website is not accurately described as a strictly RESTful API: ordinary web
pages primarily use Inertia responses, redirects, sessions and some focused
JSON requests. See [the API contracts](api/RIDER_OPERATIONS_API.md) for the exact
interface rather than claiming every route follows pure resource-only REST.

### How does the rider app connect?

It calls the Laravel `/api/v1` endpoints and supplies a permitted bearer token
for protected operations. Sanctum token records and Bagoo's custom middleware
check the current rider and token abilities. Shared services still own parcel,
company, custody and cash rules. This web review verifies that server boundary;
Flutter packages, SDK versions and device behaviour require that repository's
own evidence.

### Does signing in mean an account is allowed to do everything?

Authentication identifies the account. Approval, current activity, fixed role,
ownership, company/hub scope and action state determine what it may do. A
pending buyer may browse and view permitted personal pages, but checkout still
requires an active approved buyer. There are five stored account roles;
pickup/delivery are phases of `courier`.

### How do you protect stock and money?

Server-side validation, current-source checks, transactions, record locks,
constraints and retry identities protect the relevant writes. For example,
retrying a successful checkout returns its original orders, and seller
settlement requires both buyer completion and reconciled cash. Tests provide
evidence for these cases; naming a framework alone does not prove security.

### Does having visible API endpoints mean the database is public?

An HTTP endpoint is a defined application entry point. Public catalogue/tracking
reads expose only their allowed data, while protected actions require the
appropriate current authentication and authorization. The client asks Laravel
to perform an operation; it does not receive direct PostgreSQL access. Compose
uses internal database/PHP networking and loopback database host publication.
Actual Internet exposure, HTTPS and firewall rules still need live deployment
checks; a route list cannot certify those settings.

### Is order tracking live GPS?

It shows recorded parcel milestones and authorized current information.
Existing maps can display saved stop coordinates. This does not establish
continuous rider-location tracking, automatic ETA or a WebSocket system.

### Why do tests use SQLite when the application uses PostgreSQL?

SQLite `:memory:` isolates ordinary tests from real application data. It is
useful for HTTP, validation and transaction checks. PostgreSQL-specific locks,
constraints and simultaneous operations need their own permitted verification;
an in-memory result cannot prove those runtime details.

### Is the payment system connected to a bank?

The implemented checkout is cash on delivery. The backend records collection,
handovers, reconciliation and evidence of seller payment. Recording a payment
reference is not an automatic banking transfer. The product split is 90% seller
and 10% platform, with shipping and promotion funding accounted for separately.

### Is every feature fully released?

Use [the dated roadmap](CORE_FLOW_ROADMAP.md) for the actual implementation and
verification boundary. Explain completed behaviour using its evidence, and name
deferred features such as wallets or post-delivery refunds honestly. A local
test or build is not proof of a successful Azure/device demonstration.

## Prepare the demonstration

1. Read [Getting started](../GETTING_STARTED.md) for the actual seeded account
   names and local setup. Demo fixtures are synthetic; use a prepared environment
   and keep credentials/private documents out of presentation screenshots.
2. Confirm the deployed commit, compiled assets and migration state using the
   existing [deployment guide](WEB_AUTH_DEPLOYMENT.md). Do not reset the server
   database to make a presentation appear ready.
3. Choose an existing supported buyer/seller/hub route and confirm each required
   account can open its own portal before the presentation.
4. Show a real saved order and its custody history. Present buyer receipt,
   counted cash and seller payment as separate, evidenced actions.
5. Keep a local explanation of the architecture and saved evidence available
   if network, external maps, email or hosting is unavailable. Describe that
   limitation instead of showing invented success.

For a short answer to memorise: **Laravel enforces the rules, React presents
the interface, Inertia connects the web pages, PostgreSQL stores the records,
and Docker/Azure provide the deployment environment.**
