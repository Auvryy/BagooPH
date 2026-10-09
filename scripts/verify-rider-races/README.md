# Isolated Rider PostgreSQL races

This standalone harness exercises the real native HTTP kernel with independent
PostgreSQL connections. Ordinary repository tests remain SQLite :memory:.
Run this harness only with explicit approval for a disposable PostgreSQL exception;
the opt-in flag is not permission to use development or production databases.
Current execution evidence belongs in [the roadmap](../../docs/CORE_FLOW_ROADMAP.md).

The runner requires the existing local application image and a local Unix Docker
endpoint. It starts PostgreSQL 16 on a uniquely named internal Docker network,
without published ports. A randomly named database uses temporary memory-backed
storage. The repository is mounted read-only; runtime/log/proof/result artifacts
use a new ignored directory. Cleanup removes only this uniquely named project.
The test refuses an existing schema and checks the actual database identity.
No development/Azure migration, reset, seed, publication or deployment runs.

```sh
scripts/verify-rider-races/run-approved.sh --approved-disposable-postgres
```

Each race opens two PHP processes/connections and releases them at a shared barrier:

- Different riders compete for one eligible pickup: exactly one assignment wins.
- The same actor/key repeats a pickup: both receive one original committed result.
- The same actor/key repeats a private-proof COD delivery: one outcome/collection commits.
- Delivery and failure compete with different keys: one outcome commits; the losing
  request returns stale state or a hidden terminal task, with no second collection/attempt.

The test checks different database process IDs, retained original results and owned
reconciliation. Tokens and image bytes travel through stdin, never process arguments
or retained intent files. SQL/lock timeouts bound waiting. Generated fixtures use the
existing real commerce/hub/manifest writers. No synthetic direct status shortcuts
replace domain acceptance. The actor foreign key remains enforced and is initially
deferred on PostgreSQL to avoid taking an early account key-share lock before domain
order/parcel/user locks. SQLite ignores this PostgreSQL-only constraint timing.

Inspect `.codex/native-api/postgres-races/<run>/run.log`, `postgres-races.xml` and
`race-results.jsonl`. A setup error or failed assertion is a failed gate. Artifacts
contain only disposable test data; do not use the runner for live customer records.
A passing run does not establish Azure deployment or Flutter/device acceptance.
