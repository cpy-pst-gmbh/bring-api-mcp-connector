Markdown for the privacy policy and the imprint, served from `/privacy` and
`/imprint` when `PRIVACY_POLICY_URL` or `IMPRINT_URL` names a file instead of
an address elsewhere:

    PRIVACY_POLICY_URL="legal/privacy.md"

This directory is mounted read-only into the container, where the path
resolves as written above. Running the app from a checkout instead, paths are
relative to `app/`, so the same file is `../legal/privacy.md`.

The files are not in the repository — a privacy policy describes one operator's processing, and
shipping a generic one would invite publishing it unread.

`MAIL_SIGNATURE` works the same way and takes `legal/signature.md` — that one
is only ever a file, never an address.

`docs/privacy-policy.skeleton.md` lists what this application actually stores,
as a starting point for writing your own. It is not legal advice.
