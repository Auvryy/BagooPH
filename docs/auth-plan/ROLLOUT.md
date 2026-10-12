# Authentication validation rollout

Apply the normal code and asset deployment. This delivery adds no database
migration and needs no database reset or reseeding. Preserve existing accounts,
approval records, evidence and original sign-in emails.

## Password compatibility

New passwords require 12–128 characters and allow passphrases. This limit
applies to registration, both reset paths and the existing account password
writer; login continues to accept existing credentials. Argon2id verifies the
complete accepted password instead of bcrypt's first 72 bytes.

The new default is `HASH_DRIVER=argon2id`. If the deployment already sets
`HASH_DRIVER`, update its override to `argon2id` before rebuilding Laravel's
configuration cache. The supplied PHP image supports Argon2id. Production
defaults use 65,536 KiB memory, one thread and four iterations; the smaller
costs in PHPUnit are restricted to isolated tests. Confirm login and verification
latency on the actual host rather than copying test costs to production.

Existing bcrypt hashes remain readable. Laravel web login rehashes an existing
credential after successful authentication; an account needs no forced password
reset. Existing OTP challenges also remain verifiable. A previously issued
native bearer can require sign-in again after its stored password fingerprint
changes during rehashing. The native login continues to verify existing bcrypt
credentials.

Once Argon2id hashes have been saved, retain a compatible hashing configuration
in any rollback. Returning to an older bcrypt-only configuration can reject
those credentials. Do not rewrite password values or destroy accounts to roll
back this delivery.

## Deployment and acceptance

After the user publishes and merges the branch, pull the selected revision in
the deployment repository, set the hashing override if needed, then run:

```sh
./bagoo.sh deploy
./bagoo.sh verify
```

These commands build the frontend, apply pending additive migrations and rebuild
configuration. They do not confirm email delivery or interactive registration.
Keep the existing HTTPS, session and mail configuration described in
[web authentication deployment](../WEB_AUTH_DEPLOYMENT.md).

Verify a real delivered code and a rejected/expired code on the deployment.
Check retry, resend cooldown, session expiry, login/logout and pending versus
approved portal access. Check both role hosts and their local aliases where
applicable. Record the deployed revision and actual results; a passing isolated
test cannot establish SMTP delivery or live access. Interactive browser/device
checks require explicit task authorization.

## Form and native contract

The four web registration forms use the shared server validator for each step
and the complete form before requesting OTP. Final submission validates again.
The preflight endpoint does not create an account, store evidence or send mail.
Complete email syntax and verification govern acceptance, with custom domains
and `@bagoo.test` demos retained. No popular-provider allowlist is introduced.

The native options expose the password limits. Coordinate the separate Flutter
client using the [rider account API](../RIDER_ACCOUNT_API.md); no mobile source
changes are included here. Readable terms/privacy acknowledgement, recorded
acceptance and the combined live release gate remain separate AF03/AF04 work.
