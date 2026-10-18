# Navigation Measurements and Release Evidence

This records the selected PF04/PF05 local acceptance on October 18, 2026.
It separates actual server measurements, controlled client behavior and static
asset budgets. None of these establishes Azure click-to-render latency.
The combined delivery starts from `9b1b3c8`; implementation and verification
are recorded in [the roadmap](../CORE_FLOW_ROADMAP.md#october-18-2026-pf04-pf05-admin-navigation-and-measured-acceptance).

## Method and comparison boundaries

The opt-in `tests/Performance/NavigationBenchmarkTest.php` runs the real Laravel
HTTP kernel in Docker PHP 8.4.24 with isolated SQLite `:memory:`. Both revisions
use seed 405, 32 accounts, 37 products, two shops and one parcel, with the
fixture clock frozen at `2026-10-18T12:00:00Z`. Each exact route/query/partial
shape has one excluded warm-up and ten measured reads. Database setup and
actor selection are outside the timed interval. Responses are JSON; network,
DOM rendering and image loading are excluded. Baseline and candidate were
separate runs on the same shared local machine, without controlled CPU load.
The unchanged role timings show ordinary variation and must not be attributed
to an optimization. Query and payload differences are stronger evidence here.

The client experiment runs the actual shared policy, route allowlist and search
controller under Node v26.11.1, with controlled asynchronous
transport: 40ms for page data and 70ms for a page-code unit. It alternates
preparation disabled/enabled on the same candidate build and takes ten paired
samples for every route/scenario. Code units represent the destination and its
static dependencies; the already-loaded app and source page are excluded.
In-flight intent starts 20ms before activation; expiry advances the policy clock
past its 10-second lifetime without discarding the module cache. Private page
requests start on activation and never reuse prepared private records.

These are transport/page-readiness timings, not actual browser, React paint,
input-to-paint or image-transfer measurements. The controlled delays establish
ordering and reuse behavior; they cannot establish a deployment speed target.
Median and p90 use interpolation over ten samples; that small sample is not a
production service-level estimate. The generated report retains every sample,
range, mean, standard deviation, request count and byte budget.

## Actual endpoint samples

Times below are milliseconds, as baseline to candidate. Bytes are uncompressed
JSON body bytes, excluding headers. Unchanged query/payload values are shown
once. Every row has ten samples per revision.

| Case | Median | p90 | Queries | JSON bytes |
|---|---|---|---|---|
| Catalogue results | 47.91 → 41.62 | 56.02 → 43.79 | 24 | 44,367 |
| Buyer orders section | 35.41 → 24.13 | 44.95 → 27.33 | 35 | 5,117 |
| Seller products | 34.06 → 25.71 | 41.36 → 43.08 | 24 | 21,720 |
| Rider work dashboard | 41.03 → 42.45 | 49.70 → 53.43 | 36 | 2,878 |
| Hub parcel registry | 48.70 → 38.26 | 54.84 → 40.33 | 42 | 7,728 |
| Admin users, page 2 | 19.82 → 12.22 | 24.37 → 13.08 | 14 → 13 | 9,926 → 4,945 |
| Admin user context only | 16.10 → 11.06 | 17.92 → 11.32 | 14 → 11 | 2,607 |
| Admin products, page 2 | 25.79 → 14.56 | 27.93 → 16.27 | 15 | 38,203 → 6,843 |

Admin user rows now omit unused shop/account evidence and product rows omit
unused descriptions and full shop/category records. The rendered columns remain
available; details and decisions still use their own fresh authorized requests.
Context-only user updates avoid the directory count, rows and unused shop read.
Other role endpoints retain their query counts and payloads; their elapsed-time
variation is not evidence of faster server work. In particular, the rider
sample's median and p90 increased slightly despite unchanged work.

## Controlled client samples

Each cell is median / p90 in milliseconds with preparation enabled. The
baseline for each paired scenario is an ordinary unprepared visit on the same
build, approximately 110ms under these fixed delays; it is not the old global
Vite warming strategy. Cold enabled/disabled medians remain within 0.1ms for
these samples and establish no meaningful cold-speed difference.

| Destination | Cold | Completed preparation | In-flight preparation | Expired preparation |
|---|---|---|---|---|
| Catalogue results | 110.78 / 111.40 | 0.21 / 0.32 | 90.56 / 90.76 | 40.34 / 40.87 |
| Buyer orders section | 110.49 / 111.25 | 40.20 / 40.44 | 49.57 / 50.43 | 40.21 / 40.27 |
| Seller products | 110.41 / 110.47 | 40.18 / 40.22 | 49.37 / 50.45 | 40.17 / 40.22 |
| Rider work dashboard | 110.34 / 110.46 | 40.18 / 40.56 | 50.33 / 50.62 | 40.21 / 40.42 |
| Hub parcel registry | 110.36 / 110.38 | 40.17 / 40.21 | 50.28 / 50.43 | 40.16 / 40.19 |
| Admin users, page 2 | 110.34 / 110.52 | 40.17 / 40.20 | 50.28 / 50.37 | 40.14 / 40.16 |

Completed and in-flight catalogue preparations each produce one speculative
data request and **zero duplicate foreground data requests** in all ten samples.
Expiry discards that data: the earlier preparation plus a new fresh request costs
two data requests. Every private-role scenario has zero speculative page-data
requests and one fresh foreground request. A private import may remain in the
browser module cache after its preparation metadata expires; expiry cannot
unload a module. No scenario exceeds one speculative candidate at a time.

The search controller returns from a two-update input sequence in a median
0.087ms (p90 0.173ms), schedules the final query at
300.70ms (p90 301.18ms), and reaches the controlled result at
341.05ms (p90 341.54ms). Each of ten samples submits one final
query. This measures controller return/scheduling, not rendered input latency.

Failed and cancelled preparation each receive ten additional samples for the
catalogue and admin representative. All fall back to one fresh foreground data
request with no cached-data hit. A failed catalogue preparation makes two data
attempts including the failed one; a failed private import still fetches no
private data and can require a later foreground import. Cancellation stops the
catalogue candidate; an already-started private import remains unabortable and
can finish alongside fresh navigation. Failure/cancellation counts are retained
in the generated report. Separate frontend checks exercise visible retry,
offline/hidden/Save-Data behavior, stale responses, composition and invalidation.

## Static-asset cost and unused preparation

The removed load-triggered Vite waterfall traversed the app's dynamic imports,
including unrelated portals. The current candidate manifest gives the following
potential cold-client budget after the source page is loaded. This is a manifest
budget, not measured wire traffic: a browser can ignore prefetch hints or already
have assets cached. Removing it preserves normal initial module preloads and
lets the reviewed policy decide which likely destination to prepare.

| Loaded source page | Former eligible background files | Raw bytes | Calculated gzip bytes |
|---|---|---|---|
| Buyer/Home | 203 | 1,908,953 | 571,537 |
| Seller/Dashboard | 215 | 1,971,854 | 600,171 |
| Courier/Profile | 209 | 1,887,237 | 566,729 |
| Hub/Dashboard | 217 | 1,977,809 | 601,948 |
| Admin/Dashboard | 217 | 1,984,050 | 602,596 |

Reviewed intent prepares only one destination at a time. Its static dependency
budget after the specified source page is loaded is:

| Destination | Source page | Files | Raw bytes | Calculated gzip bytes |
|---|---|---|---|---|
| Buyer/Search | Buyer/Home | 2 | 23,274 | 4,769 |
| Buyer/Profile | Buyer/Home | 27 | 112,184 | 35,125 |
| Seller/Products | Seller/Dashboard | 12 | 86,938 | 21,615 |
| Courier/Deliveries | Courier/Profile | 13 | 55,248 | 18,144 |
| Hub/Deliveries | Hub/Dashboard | 3 | 17,481 | 5,081 |
| Admin/Users | Admin/Dashboard | 3 | 9,261 | 3,801 |

An abandoned private intent can waste that destination's static bytes, but
fetches no owned records. An abandoned catalogue result can additionally cost
44,367 JSON bytes in this fixture. The experiment includes one abandoned
preparation for each representative. It does not infer a real-world cache-use
rate from deliberately completed samples. Cancelled data keeps its full byte
budget in the report because an abort cannot establish that server work or
transfer stopped. No gallery or advertisement destination is prepared.

Cold opening of an unprepared page can still require its code chunk. Removing
blanket warming trades unconditional cross-portal downloads for bounded useful
intent; this is not a promise that every unprepared click is faster than a
previously warmed browser.

The image audit found 34 existing product JPEGs totaling 21,898,888 bytes,
with dimensions 1024×1024, 1004×1024 or 896×1200; the largest is 914,659 bytes.
The existing product card reserves a square image region and remains lazy.
Original detail/review image sources and deployment transfer/decoding can still
be expensive. No new image preload, full-gallery warming, binary replacement,
responsive image service or generated thumbnail pipeline is introduced here.
Further image compression needs its own measured visual/asset acceptance.

## Reproduction and release boundary

Use the same opt-in benchmark harness on the intended baseline and candidate
revisions. The recorded baseline is `9b1b3c8`; the harness was added later, so
copy only that harness into a disposable baseline checkout when reproducing the
historical comparison. Preserve the working checkout. Never treat two runs of
the same implementation as evidence of a code improvement.

Create `.codex/navigation-run` locally. Run the command below once on the
baseline; on the candidate, change the output name to `endpoint-after.json`.
The PHPUnit configuration and test both enforce isolated SQLite in memory.

```bash
docker compose exec -T \
  -e APP_ENV=testing \
  -e APP_CONFIG_CACHE=/tmp/bagoo-navigation-config.php \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e NAVIGATION_BENCHMARK_OUTPUT=/var/www/html/.codex/navigation-run/endpoint-before.json \
  app php vendor/bin/phpunit tests/Performance/NavigationBenchmarkTest.php
```

Build the candidate, then compare both reports with the current locked tools:

```bash
./bagoo.sh npm run build
node scripts/measure-navigation.mjs \
  --endpoint-before .codex/navigation-run/endpoint-before.json \
  --endpoint-after .codex/navigation-run/endpoint-after.json \
  --output .codex/navigation-run/measurements.json
```

The client report is fixture-only and contains no credentials or owned response
bodies. Defaults are ten repetitions; `--samples` accepts 10–30. Keep generated
reports local unless deliberately reviewing a sanitized export. Source route,
fixture, component, source page, build-manifest hash and method are retained.

All five planned batches have selected implementation and local acceptance
coverage. Real browser click-to-render, touch/device layout, deployed network
conditions, cross-device sessions and simultaneous PostgreSQL behavior remain
unverified. Follow [the deployment/manual checklist](ACCEPTANCE.md#combined-release-checks)
when explicitly running those checks. No Azure deployment, data reset, Flutter
change, new service or production performance claim is part of this evidence.
