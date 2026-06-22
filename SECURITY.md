# Security Policy

## Supported Versions

Votepit is young and receives security fixes only for the latest released
version. Please update before reporting.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security reports.**

Preferred channels (in order):

1. **GitHub Private Vulnerability Reporting** — open the repo on GitHub →
   *Security* → *Report a vulnerability*. This is encrypted and private.
2. **Email** — send a description to the maintainer at the address listed in the
   repo profile. If the report contains sensitive details, please request a
   PGP/public key first.

Please include:

- Affected version (commit hash or release tag).
- A minimal reproduction (steps, request, payload).
- Impact assessment (what an attacker could do).
- Suggested fix, if any.

## Response Time

This is a solo-maintained project. Acknowledgement is aimed at within
**72 hours**, and a first assessment within **7 days**. Complex issues may take
longer; you will be kept informed.

## Coordinated Disclosure

We follow coordinated disclosure: the report stays private until a fix is
available, then we publish an advisory together with the release. We are happy
to credit reporters (or keep them anonymous on request).

## Scope

In scope: any vulnerability in Votepit's own code that affects confidentiality,
integrity, or availability — including the magic-link authentication flow,
voting integrity, board scoping, output escaping, and access control.

Out of scope: vulnerabilities in third-party dependencies (report them upstream),
self-inflicted issues from ignoring this documentation, or findings from
automated scanners without a working proof of concept.

Votepit is designed **security-by-default** (server-side integrity, prepared
statements only, output escaping, CSRF protection, rate limiting). Reports that
confirm these guarantees hold are appreciated too.
