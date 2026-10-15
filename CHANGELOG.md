# Changelog

## Unreleased

The first release.

### Added
- **Steps per collection** for Statamic 6 Pro, each approved by roles, user groups, users, a permission or anyone who can publish. The last approval publishes through Statamic, recorded in the entry's revision history.
- **Actions** in the entry's ••• menu: Submit for sign-off (with a note), Approve (with a comment), Send back (with a required reason), and Withdraw from review.
- **The lock**: entries in review can't be changed, so what's approved is what goes live. Content that changes anyway has to be submitted again.
- **The Sign-off panel** in each entry's sidebar: where it is, who approved each step, why it was sent back, what to do next, and earlier rounds.
- **Compare changes**: each changed field side by side with the changed words highlighted, against the live version or what was submitted last time.
- **Content → Sign-off**: what's waiting for you, everything in review, and the history of every round.
- **The `sign_off` dashboard widget**: waiting for you, sent back to you, and yours in review.
- **Emails** to reviewers when an entry reaches their step, and to authors when it's sent back or signed off. Queued, and never stop a review if they fail.
- **Publish without sign-off** permission, recorded in the entry's history. Unpublishing follows Statamic's usual rules, unless turned off.
- **Setup checks**: problems shown to people who can configure collections, and `php please sign-off:check` for deploys.
- **Storage** in flat files (indexed by the Stache) or the database.
- **Events**: `EntrySubmitted`, `EntryApproved`, `EntryRejected`, `SubmissionWithdrawn`, `EntrySignedOff`, and a PHP API.
- **Documentation** in `docs/`.
