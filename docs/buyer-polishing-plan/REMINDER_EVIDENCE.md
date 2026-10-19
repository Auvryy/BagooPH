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

No production setup, scheduler start, notification insertion/replay, publication
or deployment was performed. The runtime prerequisite is verified; the actual
deployed reminder remains **Not Run**, pending approved creation of a future
demo source or user-led setup. Local checks do not complete that observation or
increase deployed-readiness ratings.
