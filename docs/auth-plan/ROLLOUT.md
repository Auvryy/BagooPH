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

Each web form collects required first and last names and an optional middle
initial. Invalid characters produce immediate inline feedback without deleting
what the applicant entered. Names permit Latin letters with accents, spaces,
straight or curly apostrophes, hyphens and periods; digits and hidden characters
are rejected. The server derives the display name from the normalized parts,
validates those parts on every role's first step and validates again at signup.
First and last names require 2–50 characters each. The optional middle initial
accepts one letter with an optional period (M or M.), at most 2 characters.
New first/last edits stop at 50 characters and initial edits at 2; overlong paste
is rejected rather than shortened. The initial control is narrower on shared
rows. Its `middle_initial` request alias is validated on the server and stores
the initial in the existing optional middle-name field. Legacy full middle-name
input remains 1–50 characters; existing records and name-only clients remain
supported. The combined limit stays 2–100 characters including separating spaces.

New signup emails trim outer spaces, become lowercase and stop at 255
characters. Duplicate ownership remains case-insensitive, and existing sign-in
addresses are preserved. Internal whitespace and hidden characters remain
invalid. Mobile fields show a fixed `+63`, allow exactly ten national ASCII
digits starting with 9, block extra digits/letters and report incomplete input.
Supported complete-number prefixes work on paste; malformed or overlong pasted
numbers are rejected without changing them into a different number. Business
contacts retain the server's mobile-or-landline validation.

Obvious missing or malformed identity/contact details are checked locally before
preflight. Passing this feedback still requires the server's confirmation; file,
ownership, category and other domain checks remain authoritative on the server.
Deploy the PHP changes and freshly built frontend together. No schema change or
database reset is needed for separated names.

An existing signed-in session can conflict with a guest-only background request.
Those JSON/API requests now return a clear sign-out message, preserving the
session and normal page-navigation redirects. Check registration with both a
guest session and an already signed-in session; the latter must not send an OTP
or return an HTML redirect as if validation succeeded.

The native options expose the password limits. Coordinate the separate Flutter
client using the [rider account API](../RIDER_ACCOUNT_API.md); no mobile source
changes are included here. Readable terms/privacy acknowledgement, recorded
acceptance and the combined live release gate remain separate AF03/AF04 work.
