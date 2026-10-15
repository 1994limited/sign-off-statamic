# Troubleshooting

## Start with the check

```bash
php please sign-off:check
```

It lists each collection with its steps, marks the ones where sign-off isn't running, and says why:

```
  Collection ........................................... Steps
  pages .................................... Legal → Publisher
  news (not running) ................................... Editor

   ERROR  [news] Revisions are off for this collection, so sign-off isn't running for it. Turn on revisions in the collection's settings.

   WARN  [news] Editor: there is no role "editors".
```

Errors stop sign-off for a collection, and entries there are published as usual until they're fixed. Warnings are things that will go wrong: a step that only super users can approve, a step whose handle may change. The command exits with an error for either, so it can fail a deploy.

People who can configure collections see the same in the Control Panel.

## Entries publish without sign-off

Sign-off only runs when all of these are true. The check tells you which isn't.

- Statamic **Pro** is enabled (`STATAMIC_PRO_ENABLED=true`, or `pro` in `config/statamic/editions.php`).
- Revisions are on for the site (`STATAMIC_REVISIONS_ENABLED=true`).
- Revisions are on for the collection (`revisions: true`).
- The collection is in `config/sign-off.php`, with at least one step.

If it's all set up, check who published it: people with the *Publish entries without sign-off* permission (and super users, unless `super_users_can_skip` is off) can publish directly. It's recorded in the entry's sign-off history as *Published without sign-off*.

## Nobody can approve a step

A step's approvers have to match someone: check the role, group, user or permission names against the check's output. Until they match, only super users can approve it, and they're emailed about it.

The person who submitted an entry can't approve it unless `self_approval` is on: someone else has to.

## "This entry changed after it was submitted"

The content changed after it was submitted (with the lock off, or from code), so the approvals no longer applied. The author submits it again. With the lock on (the default), entries in review can't be edited in the Control Panel.

## The author can't edit their entry

It's in review, and locked. They can choose **Withdraw from review** from the ••• menu, make their changes, and submit again. Or a reviewer can send it back.

## The ••• menu has no Sign-off actions

- The entry is in a collection without steps, or sign-off isn't running for it.
- Nothing to submit: a published entry with no changes in its working copy. Edit and save first.
- It's waiting for a step you can't approve. The Sign-off panel says who can.

## Emails aren't arriving

- Check your mail settings with any other email from the site, for example a password reset.
- With a queue connection other than `sync`, a queue worker has to be running: `php artisan queue:work`.
- Failed emails are logged in `storage/logs`, and never stop a review.
- `SIGN_OFF_MAIL` isn't `false`.

## The Sign-off panel or page looks wrong after an update

Republish the Control Panel assets:

```bash
php artisan vendor:publish --tag=sign-off-statamic --force
```

## Moving between storage drivers

Switching `SIGN_OFF_DRIVER` doesn't move existing submissions. Switch when nothing is in review, or entries in review will need submitting again (their published content isn't affected).

## Removing Sign-off

Take a collection out of `config/sign-off.php`, and it goes back to Statamic's own rules: entries in review are unlocked, and anyone who can publish can publish them. To remove the addon, `composer remove 1994/sign-off-statamic`; the history in `storage/sign-off` (or the `sign_off_submissions` table) can then be deleted.
