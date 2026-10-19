# BP04: Reminder and release observation

Proposed branch: `test/buyer-reminder-release-check`.
Active reference: `BUY-CHAT-004`. Follow the [shared plan](README.md).

The [reminder evidence](REMINDER_EVIDENCE.md) records current isolated support and
keeps the missing deployed observation explicit.

## Outcome

Establish that an eligible buyer receives one genuine scheduled sale reminder
before its start, linked to the correct current product. Existing isolated clock,
ownership and retry tests remain useful evidence but do not establish that the
deployed worker ran.

## Entry conditions

- Record the actual applicable commit on the intended test environment. The
  local application, deployed application and scheduler must use the intended
  code and the additive purchase/sale/reminder schema.
- Read the real deployment helper and
  [deployment guidance](../WEB_AUTH_DEPLOYMENT.md). Inspect service state and
  registered commands without printing environment secrets or connection data.
  A listed command alone is insufficient; a functioning scheduler is required.
- Prepare one approved eligible shop/product and one real future flash slot.
  Keep the precise scheduled time and interpretation in Asia/Manila. Choose an
  eligible approved buyer and an explicit owned subscription.
- Publication and deployment remain user actions or separately authorized work.
  This plan does not authorize an unattended production deployment, scheduler
  start, database reset, sale creation or repeated notification mutation.

## Targeted observation

1. Record the sale/subscription before the reminder window. Keep passwords,
   tokens, private documents and unrelated account data out of evidence.
2. Let the genuine scheduled process run. Observe the retained delivery intent
   and the buyer's notification before the sale starts; verify its product link.
3. Check time, owner and source identity. Repeated scheduled execution must not
   create a second notice. A reminder queued during an outage is not yet proof
   that the buyer received it.
4. In isolated checks, opt out, end a slot, restrict the buyer/shop and move past
   the start boundary. Pending stale delivery is discarded rather than sent late.
   Do not simulate these destructive branches against ordinary production orders.
5. Capture the actual result and release limits. If the worker, eligible setup or
   required observation is unavailable, retain Not Run/Blocked with that precise
   reason. Do not invent a successful notification or manually replay an already
   completed reminder to make the screen look ready.

Use existing `SaleReminderTest`, `NotificationDeliveryTest`, controlled deployment
helper checks and the relevant reminder components to investigate a real failure.
Only a confirmed selected-scope defect calls for a new application change and its
focused checks/build. Reading a service or registering a schedule is not that fix.

## Final handoff

Reconcile the 24 original polishing cases and the extra control finding against
their recorded evidence. Retain the seven PAY and four RET cases under their
[prerequisite gates](05-payment-and-return-prerequisites.md). Do not reopen all
57 excluded likely passes solely to produce a larger test count.

Report actual local commits, publication/deployment state, executed methods,
unresolved observations and precise runtime prerequisites. Keep the strict
acceptance status separate from a forecast or a documented policy decision. A
documentation task or successful build does not complete an unobserved release.
