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
3. One approved adult demo buyer subscribed more than ten minutes before start.
4. The genuine scheduled intent, actual buyer notification, correct target and
   timestamps strictly before the slot begins.
5. Later scheduled cycles retaining exactly one source and notification.

No production setup, scheduler start, notification insertion/replay, publication
or deployment was performed for this observation. No actual sale/subscription
source or scheduler result was supplied. The deployed check therefore remains
**Not Run**, with runtime setup/source information pending. Local tests do not
complete it or increase deployed-readiness ratings.
