# Privacy Policy

<!--
A starting point, not legal advice and not a finished document. What follows is
an inventory of what this application actually stores and sends where, so the
technical half of the text does not have to be reconstructed from the code.
Everything in angle brackets is yours to fill in, and the obligations that come
with running the service are yours to check.
-->

**Controller:** <name, address, email>

## What is stored

| Data | Why | Where |
| --- | --- | --- |
| Email address | It is the Bring! account name and identifies the account here | Database |
| Bring! password | Sent to Bring! on your behalf when a connector acts on your list; kept encrypted (libsodium `secretbox`), never displayed | Database |
| Connector entries | One OAuth client per connection you create, so single connections can be revoked | Database |
| Access and refresh tokens | Keep a connected assistant signed in | Database |
| Sign-in link tokens | One-time, valid 15 minutes | Signed, not stored |

There is no separate password for this service. Bring! verifies the one you
type, and the account here is created on the first successful sign-in.

## Who else receives it

- **Bring! Labs AG** — every sign-in and every list operation goes to their
  API. Their privacy policy applies to what happens there.
- **<the assistant vendor, e.g. Anthropic PBC>** — a connected assistant sends
  the items you ask it to add. Your Bring! password is never part of a token
  and never passes through it.
- No analytics, no advertising, no third-party fonts or scripts.

## Cookies

A session cookie while you are signed in. Nothing else.

## Retention

Data is kept until you delete the account, which removes the account, the
stored password and every connector at once. Expired tokens are cleared
periodically.

An account that goes unused for eleven months receives an email announcing its
deletion; after twelve months without a sign-in or any connector activity it is
deleted along with the stored password and all connectors. Signing in once
resets this.

## Your rights

<Access, rectification, erasure, portability, objection, complaint to a
supervisory authority — state how you can be reached for these.>

Last updated: <date>
