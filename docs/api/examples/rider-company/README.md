# Rider company response examples

These JSON files were captured from successful isolated backend tests and use
synthetic Bagoo accounts. IDs, references, correlation IDs and times describe
those test records; obtain current values from the running API. No bearer token
or original private evidence is included.

`manifest.json` maps every example to its executable schema in
[rider-operations.openapi.json](../../rider-operations.openapi.json). Examples
cover account/Home discovery, directory/detail, empty and pending requests,
current offers, all supported terminal outcomes, original write/reconciliation/
replay results, saved assignment after acceptance or company restriction, and
private validation/conflict/unknown-command errors.

The backend acceptance cases live in
`tests/Feature/Courier/RiderCompanyApiTest.php`. Setting
`RIDER_COMPANY_CONTRACT_ARTIFACTS=1` during that isolated SQLite run captures
actual responses in the ignored `.codex/rc03/contract-samples` directory. Never
point tests at development or production databases.

With an existing Ajv 2020 verification tool available, run:

```sh
AJV_2020_MODULE=<INSTALLED_AJV_2020_MODULE> node scripts/verify-rider-company-contract.cjs .codex/rc03/contract-samples
```

The verifier compiles every published schema, validates all examples and actual
captures, checks counter/consent rejection boundaries and verifies ten unique
company methods. It installs no dependencies and never contacts an application
database. This is backend wire evidence, not Flutter/device acceptance.
