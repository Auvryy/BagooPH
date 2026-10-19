# Scheduled reminder acceptance evidence

BP04 requires an actual deployed reminder. Current local evidence at `78405a6`
is **10 passing reminder tests / 93 assertions**, using Docker PHP 8.4 and SQLite
`:memory:`. These checks cover owned subscriptions, the exact pre-start window,
once-only delivery, current product links, opt-out, ended/ineligible sources,
failed delivery retry, stale delivery after start and rejoining before start.

The inspected application registers `sales:send-reminders` every minute. Its
reminder lead time is ten minutes. Subscribing inside that window may deliver
immediately, which does not establish that the deployed scheduled process ran.
The Compose scheduler command is `php artisan schedule:work --no-interaction`.
These are verified code/configuration facts, not observed process or delivery
results on a server.

The prepared [deployed procedure](04-reminder-and-release.md) requires:

1. The actual published commit, additive schema and scheduler process.
2. One approved eligible demo product with a non-overlapping future flash slot.
3. One approved eligible demo buyer subscribed more than ten minutes before start.
4. The genuine scheduled intent, actual buyer notification, correct target and
   timestamps strictly before the slot begins.
5. Later scheduled cycles retaining exactly one source and notification.

Read-only Azure checks on October 19, 12:51–12:53 Asia/Manila established deployed
commit `7c1bb59`, a running scheduler container and its actual `schedule:work`
process, and the four required sale/reminder/notification tables. The deployed
reminder service, console schedule and notification delivery service match the
current local source byte for byte. This verifies the applicable existing reminder
runtime; it does not establish deployment of the newer return work.

The same read found **no future flash slots and no reminder subscriptions**.
Consequently there is no existing event whose actual delivery can be observed.
An eligible demo product and the existing eligible seeded buyer were identified
without changing them. Existing documented legacy birth-date eligibility is
preserved; no birth date or approval is invented for the test fixture.

A bounded setup/cleanup action is prepared for explicit approval. Its separate
isolated SQLite checks passed **4 tests / 34 assertions**: one future source,
exact retry, unchanged ordinary data and existing notices, complete rollback on
a failed subscription, and ending only the prepared slot with its history kept.
These are fixture checks, not a deployed notification result.

That preparation did not create a production source or establish actual delivery.

## Authorized deployed result: October 19, 2026

The user subsequently approved one bounded demo setup. The existing seller's
eligible product #1 retained its PHP 2,850 regular price and stock 6. One flash
slot at PHP 2,565, quantity 1, was created at 13:25 Asia/Manila for 13:46, and
seeded buyer #2 subscribed. A 13:27 source read retained the subscription with
no notified timestamp, delivery intent or notification. No reminder command was
run manually and no worker was restarted.

The real scheduler's retained records establish:

- One source delivery and one actual buyer database notification at **13:36:01
  Asia/Manila**, before the 13:46 sale start, with one delivery attempt.
- The exact owned subscription and sale/product identity, and the current
  `/buyer/product/vanguard-commuter-backpack-26l` notification target.
- Successful `sales:send-reminders` worker runs at 13:36 and every later minute
  through 13:45. Later source-filtered reads, including all recipients, still
  find exactly one notification.
- Zero promotional units used; the original regular price and physical stock
  remain unchanged. Source and notification history are retained.

This is **verified retained production-delivery evidence**, observed after the
window. It is not a live pre-start observation. The planned early cleanup was
missed: the slot naturally expired unused at 14:16 and was explicitly ended at
17:03:08. Its cleanup record correctly reports `ended_before_start = false`.
The missed observation/cleanup procedure is not relabelled as passed.

## Authorized live repeat and early cleanup

The user separately approved one repeat with an independent early-cleanup watcher.
The same eligible product and buyer received a new source (#2), starting at
**17:32 Asia/Manila**, with the same bounded price and quantity. It was saved and
observed without a notification more than ten minutes before start.

The actual scheduler delivered exactly one owned notification at **17:22:01**.
The watcher observed it live at **17:22:21**, verified the exact source/recipient
and current product link, and retained one notice through the successful
**17:23:01** and **17:24:01** scheduled cycles. It ended only that test slot at
**17:24:33**, seven minutes and 27 seconds before start. The retained cleanup
record confirms `ended_before_start = true` and no reminder replay. The slot
used no promotional units; regular price and stock remain unchanged.

This completes BP04's strict live procedure. Both attempts retain their actual
source and notification history; the first attempt's late cleanup is preserved
as a procedural limitation rather than rewritten. No third source or manual
reminder execution was used.

No application publication/deployment, real order, stock change or scheduler
mutation was performed. The newer return work remains local. The actual reminder
outcome is established; this record does not raise overall deployed readiness or
certify deployment of the newer return implementation. Implementation ratings
are unchanged by this runtime acceptance evidence.
