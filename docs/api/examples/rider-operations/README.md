# Sanitized Rider operations examples

These examples illustrate the accepted [operations contract](../../RIDER_OPERATIONS_API.md)
and [OpenAPI schemas](../../rider-operations.openapi.json). They use fictional
accounts, addresses and source references. They are neither deployed records nor
Flutter/device acceptance evidence.

| Example | OpenAPI response schema |
|---|---|
| [home](home.json) | HomeEnvelope |
| [claim](claim.json) | ParcelCommandEnvelope |
| [pickup task](pickup-task.json) | TaskEnvelope |
| [final-mile task](final-mile-task.json) | FinalTaskEnvelope |
| [delivery](delivery.json) | DeliveredCommandEnvelope |
| [trip](trip.json) | TripEnvelope |
| [conversations](conversations.json) | ConversationListEnvelope |
| [message](message.json) | MessageCommandEnvelope |
| [notifications](notifications.json) | NotificationListEnvelope |
| [cash](cash.json) | CashEnvelope |
| [cash offer](cash-offer.json) | CashOfferCommandEnvelope |
| [empty queue](empty.json) | TaskListEnvelope |
| [denied](denied.json), [stale](stale.json), [service error](error.json) | Error |

The public success shapes are sanitized from exercised native test responses.
The empty/error examples are illustrative contract fixtures. Private image downloads
return binary bytes with privacy/request headers rather than a JSON envelope.
The Home example assumes its feature schemas are installed. Missing schema keeps
the affected feature unavailable; release, native restricted recovery and rider
earnings stay unavailable in either case.
Runtime checks, current readiness and deployment limits are recorded only in
[CORE_FLOW_ROADMAP.md](../../../CORE_FLOW_ROADMAP.md).
