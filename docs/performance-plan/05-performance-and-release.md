# PF05: Performance Measurement and Combined Acceptance

## Outcome and dependency

Measure the selected role deliveries, keep useful optimizations, and remove or
disable speculation that costs more than it helps. Earlier correctness and
isolation gates must pass. Do not label all portals accepted if one role remains
unselected, blocked or unverified.

## Comparable measurements

Record the same route, query/filter/page, fixture scale, build, device/client,
network conditions and database state for the baseline and changed behavior.
Separate cold navigation, completed-prefetch navigation, in-flight-prefetch
navigation and cache-expired navigation. Separate response data from page-script
loading, image transfer/decoding and rendering.

| Measure | Why it matters |
|---|---|
| Click/Enter to updated meaningful grid/page | Captures the waiting the user actually experiences |
| Input responsiveness and settled-query to correct results | Confirms typing remains smooth and late results cannot replace the current query |
| Median and slower-tail timings, with sample count and variation | Avoids reporting one lucky warm request as the normal experience |
| Speculative/foreground request count, bytes and cache-use rate | Shows the cost of pages prepared but never opened |
| Endpoint time and database query count | Distinguishes earlier work from less server work; protects the small deployment |
| Errors, cancellations, fallbacks and maximum in-flight work | Confirms degraded connections remain usable |

Collect a reproducible sample, initially at least 10 repetitions per selected
representative scenario, and describe that small sample honestly. Report
improvements only for measured cases. A completed-prefetch click should avoid
the duplicate matching data fetch; this does not promise instant images,
zero rendering time, or a fixed percentage improvement across Azure.

Meaningful acceptance is improved prepared navigation with correct current
results, preserved foreground responsiveness and no unbounded background work.
If cold navigation worsens or endpoint load becomes unacceptable, reduce the
allowlist/budget or disable idle preparation. Agree any numerical latency/load
target after collecting the baseline rather than inventing a current benchmark.

## Scoped improvements after measurement

- Reduce demonstrated over-fetching, repeated queries and unnecessary props
  using existing services and dependencies. Verify that partial responses
  avoid the expensive evaluation they intend to skip.
- Keep offscreen product images lazy. Audit image dimensions, oversized source
  files and critical page-script loading; prepare at most a measured useful
  primary asset for a real destination. Do not download every product gallery.
- Reuse existing Vite code splitting and static-asset delivery. Asset cache
  changes must not expose authenticated page data or remove private headers.
- Preserve advertisement activity semantics, truthful counters and the
  established portal design. No new monitoring service, synthetic activity,
  application-wide visual rewrite or infrastructure migration is required.

## Combined acceptance

Cover each selected role under normal navigation, hover/focus, quick clicks,
touch/scroll, typing/composition, query clearing, page boundaries, Back/Forward,
offline/recovery, slow network, hidden tab, disabled speculation and cache expiry.
Check sign-out/in, another account, changed hub/company, changed approval/access,
stale operational state, relevant mutations and late in-flight responses.

Use isolated SQLite `:memory:` for database tests. Run relevant PHP/frontend
tests and `npm run build`; broaden testing when shared cache/session changes
justify it. Use request/service measurements and automated behavior checks
first. Browser UI testing requires the user's explicit request under repository
instructions; otherwise provide the remaining manual steps and retain that
acceptance limit. Never change or reset Azure/development data for a benchmark.

Complete the handoff with per-role allowlists/exclusions, measured before/after
results, exact verification, remaining gaps and the implemented disable path.
Record lasting evidence and scoped ratings in
[CORE_FLOW_ROADMAP.md](../CORE_FLOW_ROADMAP.md). Preserve existing limitations
for Flutter, deployment and PostgreSQL concurrency unless separately verified.
Prepare a combined PR description and report every local commit; only the user
publishes. No batch is Done merely because the documentation or build passes.
