# How sign-off works

This page follows an entry from its first draft to going live, with the steps from [Installation](installation.md): **Legal**, then a **Publisher**.

## 1. The author writes and saves

In a collection with steps, an author can't publish an entry themselves: the **Published** toggle is off and can't be turned on. They write and save as usual.

- **A new entry** is saved as it is, unpublished.
- **A change to a live entry** is saved to its working copy (Statamic's revisions). The live page doesn't change.

## 2. The author submits it

From the entry's ••• menu, **Submit for sign-off**, with an optional note for the reviewers ("Updated for the new mailing list provider"). Only saved changes are sent, so save first.

The entry is now **in review**, waiting for Legal. Everyone with the `legal` role is emailed, and it appears in their *Waiting for you* list.

An entry can be submitted when there's something to review: an entry that has never been published, or a live entry with changes in its working copy. The person submitting needs to be able to edit it.

## 3. It's locked while in review

Nobody can change an entry in review, super users included. The edit form shows **Read only**. What the reviewers approve is exactly what goes live.

If the author needs to change something, they choose **Withdraw from review** from the ••• menu. The entry comes back to them to edit, and approvals so far are lost. Only the person who submitted it, or a super user, can withdraw it.

You can turn the lock off (`lock_during_review`). Then edits are allowed, but any change to the content in review sends it back to the author to submit again, so approvals never apply to content nobody approved. The same happens if the content changes some other way, for example from code.

## 4. Each step approves or sends it back

A reviewer opens the entry, checks what changed with **Compare changes** in the Sign-off panel, and chooses from the ••• menu:

- **Approve**, with an optional comment. The entry moves on to the next step, and its reviewers are emailed.
- **Send back**, with a reason, which is required ("List the analytics cookies by name"). The review ends. The author is emailed and sees the reason in the entry's sidebar. They make the changes, save, and submit again.

Steps are approved in order. A step can only be approved by its own approvers (and super users), and never by the person who submitted the entry, unless `self_approval` is on.

A new submission starts from the first step again: approvals from an earlier round don't carry over. Reviewers can compare it with what was submitted last time, to see what the author changed.

## 5. The last approval publishes it

At the last step, the button reads **Approve and publish**. Sign-off publishes the entry through Statamic, as the person who approved it:

- a new entry is published;
- a live entry's working copy replaces the live content.

It's recorded in Statamic's revision history as "Signed off: Legal, Publisher", and the author is emailed.

## Publishing without sign-off

For urgent fixes, give a role the **Publish entries without sign-off** permission (super users have it unless `super_users_can_skip` is off). People with it publish as Statamic normally lets them: the Published toggle and Publish button work as usual.

It's recorded in the entry's sign-off history as *Published without sign-off*, with who did it and any revision message. If the entry was in review, that review ends, marked as changed.

## Taking a page down

Unpublishing doesn't need sign-off, so content can come down fast: from the entry or from the Unpublish action in the entry listing, it follows Statamic's usual publish permission. With `unpublish_without_sign_off` set to `false`, only people who can publish without sign-off can take entries down.

## Each round is kept

Every submission and what happened to it (approvals with their comments, a sending back with its reason, a withdrawal, the final publish) is kept as a round of review. The entry's Sign-off panel shows the latest rounds, and Content → Sign-off → History lists them all.

| State | Meaning |
| --- | --- |
| In review | Waiting for a step |
| Sent back | A reviewer sent it back with a reason |
| Withdrawn | The author took it out of review |
| Needs resubmitting | Its content changed after it was submitted |
| Signed off | Every step approved, and published |
| Skipped | Published by someone who can publish without sign-off |

## What sign-off covers

Sign-off holds wherever Statamic checks permissions: the entry form, the actions in entry listings, and anything else in the Control Panel. It decides who may publish and edit entries in your collections, for everyone, super users included.

It doesn't stop changes that never ask for permission: your own PHP code saving entries, or someone editing the content files directly. If content in review changes like that, the review ends as soon as it's noticed: when the entry is saved, or at the latest when someone tries to approve it. So it can't be approved by mistake.

Collections without steps, and sites without Statamic Pro or revisions, are left to Statamic's own rules.

## Multi-site

Each localization of an entry is reviewed on its own, with the collection's steps. The review page shows which site each entry is for.
