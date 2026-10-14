# Exercised rider handover examples

These examples come from isolated HTTP checks of the
[handover API](../../RIDER_HANDOVER_API.md) and its
[executable contract](../../rider-operations.openapi.json). Accounts, facilities,
parcels and documents are fictional fixtures. They are not deployed records or
Flutter/device acceptance evidence.

| Example | Response schema |
|---|---|
| [Report result](report.json) | HandoverReportCommandEnvelope |
| [Involved issue list](issues.json) | HandoverListEnvelope |
| [Report awaiting review](detail.json) | HandoverDetailEnvelope |
| [Authorized pickup recovery](detail-pickup.json) | HandoverDetailEnvelope |
| [Authorized manifest recovery](detail-manifest.json) | HandoverDetailEnvelope |
| [Holder acknowledgement](acknowledgement.json) | HandoverAcknowledgeCommandEnvelope |
| [Lost-response reconciliation](command.json) | CommandEnvelope |

Evidence downloads return authorized binary bytes, not a JSON envelope.
Observations, authorization and holder acknowledgement cannot prove hub receipt
or buyer completion. Read the current detail after a command: its saved result
retains the state when that action committed.
