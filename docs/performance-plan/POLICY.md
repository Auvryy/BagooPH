# Shared Prefetch and Freshness Policy

This is the implementation contract for the proposed [performance batches](README.md).
The values below are conservative starting choices to verify and tune, not
measured production results or framework defaults.

## Scope and request budget

1. Allow only reviewed same-origin GET page reads within the current portal.
   Review controller, middleware, shared props, redirects and loaded services.
   Do not apply `prefetch` to every link globally.
2. Start pointer-hover or keyboard-focus preparation after approximately 150ms.
   Cancel the pending trigger if intent leaves before it starts. Reuse an
   in-flight or cached request for the same descriptor.
3. Allow at most one speculative data request in flight for this policy. An
   actual navigation, form or search result update takes priority. Cancelling
   the browser request does not prove that server/database execution stopped.
4. A settled search may prepare one result page. Bounded idle preparation may
   prepare one adjacent next page after approximately 1 second with no new
   input, once the current grid is ready and visible. Schedule it once per
   settled query/page, not repeatedly while idle. No scanning all products,
   loading every pagination link or navigating automatically.
5. Disable speculation while offline, while the document is hidden, when a
   relevant mutation/navigation is pending, or when supported connection hints
   indicate Save-Data or a very slow connection. These hints are optional;
   ordinary navigation works when the API is absent. Without useful connection
   information, keep the stricter budget and disable automatic idle warming.
6. Cache catalogue/search responses for up to 10 seconds initially and bound
   retained entries, initially 20. Deduplicate equivalent descriptors. Retain
   no failed, redirected, unauthenticated or rejected result as a ready page.
   Test the installed adapter's actual expiry and invalidation behavior; add
   only the smallest wrapper needed to enforce the agreed budget.
7. On touch, keep taps and scrolling immediate. A reviewed touch-intent hook may
   start one request for the tapped destination, but must not prevent normal
   scrolling or pretend that desktop hover exists. Keyboard users get visible
   focus, normal Enter activation and the same bounded preparation opportunity.

Idle is an opportunity to prepare one likely next step, not a request to keep
the network busy. Abandoned queries and links must not create a continuing
background loop. Do not duplicate existing polling or notification traffic.

## Cache identity and isolation

Build a canonical request descriptor from the exact route/origin, GET method,
normalized query and pagination, partial/full response shape and relevant
request options. Include the current account/role and company/hub context in
the surrounding cache policy; clear state when this context changes. Include
the deployed asset version where needed for the adapter's page compatibility.

Use the existing 100-character server search limit and accepted filter values.
Trim search exactly as the server does; do not invent a different case, token
or punctuation normalization that changes the query. Sort equivalent query
keys for deduplication. Page 1, page 2, another sort, another category and another
search must never share a result accidentally.

- Keep responses only in bounded, short-lived memory in the current client.
  Do not put owned page data in localStorage, IndexedDB, a service worker,
  shared CDN cache or an unscoped backend cache.
- Preserve `HandleInertiaRequests` and private API response headers. HTTP
  `no-store, private` and Inertia's JavaScript prefetch cache are separate
  mechanisms; retaining the header alone does not isolate a JavaScript cache.
- Clear affected data on relevant successful mutations, sign-in, sign-out,
  account changes, company/hub changes and expired-session/permission responses.
  A generation/context check must prevent an old in-flight response from
  repopulating the new session's cache. Include cross-tab session transitions
  in acceptance rather than assuming a full reload always occurs.
- Tags and adapter flushing may be reused where supported by the installed
  version. Verify their behavior; do not assume navigation flushes everything.
- Product pages currently include owned Bag quantities and offers. Treat the
  entire response as context-dependent even when the product itself is public.

## Freshness by data type

| Kind of data | Initial policy |
|---|---|
| Product/category/search browsing | Up to 10 seconds in memory with the complete current context; invalidate relevant local mutations and recheck purchasing on the server |
| Owned non-operational reference/history | Opt in only after the role audit; up to 5 seconds in memory if a briefly old read is acceptable and the access/revalidation contract is satisfied |
| Current permission, assignment, mutable operational queues or finance | Fresh read on activation; use smaller responses/code preparation rather than cached authority |
| Passwords, OTPs, private evidence, pickup secrets, authentication/recovery forms | No speculative data fetching or retained prefetch result |
| Writes or GET reads that acknowledge/consume/change something | No automatic fetching; preserve intentional action/display semantics |

A cached page cannot establish current permission. Private or operational
destinations that need a new authorization check must contact the server before
showing newly requested protected content or enabling an action. If that cannot
be achieved without exposing a stale snapshot, leave data prefetch off and
optimize the fresh read. Do not promise instant navigation for every screen.

An automatic request must not mark a message/notification as read, record an
advertisement view/click, consume a one-time claim, rotate a token, create a Bag,
submit a form, age away required success/error feedback, or change parcel/cash
state. Verify session flash/error handling as well as explicit database writes.
If a GET read has a side effect, exclude it unless a separately verified change
preserves intentional display/action semantics.

## Search and partial responses

Use a roughly 300ms typing debounce. Keep the latest input responsive, cancel
superseded scheduled work and ignore late responses for an older descriptor.
Distinguish loading, no results and a recoverable failure. Keep a useful prior
grid visible while a newer query is loading, without presenting it as the new
query's completed result.

Use Inertia partial reloads only within the same page component and with a
complete list of props that must stay coherent. A partial response cannot be
used as a full-page cache hit. URL, query, filters, counts, page number, result
IDs, availability and related products must agree. Server closures or service
changes are necessary where expensive props would otherwise be computed before
the partial response is selected; an `only` option alone does not establish
that database work was avoided.

## Presentation, failure and rollback

Preserve each portal's own style, focus and useful scroll state. For explicit
product pagination, bring the changed grid into view rather than preserving an
unhelpful position below it. Back/Forward restores the query and useful position.
Use a subtle pending/result announcement, not a full-screen loading interruption.
Respect reduced motion; do not add continuous animations or ornamental effects.

Fallback to a normal authorized request when preparation is missing, stale or
failed. Foreground errors remain visible and retryable. Stop automatic retries
after one failed speculative attempt for a descriptor. Do not hide real
validation, permission, offline or session-expiry errors behind an empty grid.

Each batch must be easy to disable through the implemented policy entry point
while ordinary links/forms keep working. Define that switch during PF01; do not
invent an environment variable in documentation as though it already exists.
No database reset, synthetic approval or data repair is a performance strategy.
