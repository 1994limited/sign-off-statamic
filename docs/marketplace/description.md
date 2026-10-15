**Multi-step editorial approval for Statamic Pro. Editors submit their changes, each step approves or sends them back with a reason, and nothing goes live until everyone has signed off.**

Sign-off is built on Statamic's own revisions. Changes to a live page wait in its working copy, the live page stays as it is, and the last approval publishes through Statamic, so it's recorded in the entry's revision history like any other publish.

## Steps per collection

Legal, then Brand, then a Publisher. Each step is approved by roles, user groups, named users, a permission, or anyone who can publish. Collections without steps work as they always have.

## Approve or send back

Reviewers approve from the entry's ••• menu, or send it back with a reason the author sees in the sidebar. At the last step the button reads **Approve and publish**.

## Locked while in review

An entry can't be changed while it's being reviewed, so what's approved is what goes live. Authors can withdraw it to make changes. If it changes anyway, it has to be submitted again.

## See what changed

Each field's old and new text side by side, with the changed words highlighted. Compare with the live page, or with what was submitted last time, so reviewers see only what the author changed.

## A queue for reviewers

**Content → Sign-off** lists what's waiting for you, longest wait first, everything in review, and every round's outcome. A dashboard widget shows each person what needs them.

## Emails

Reviewers hear when an entry reaches their step; authors when it's sent back or signed off.

## Ways round it, on the record

People with the **Publish entries without sign-off** permission can still publish straight away, for urgent fixes. It's recorded in the entry's sign-off history. Taking a page down doesn't need sign-off, unless you want it to.

## Checks your setup

A collection without revisions, a role that doesn't exist: people who can fix it are told in the Control Panel, and `php please sign-off:check` fails in your deploy.

## For developers

Events at every step, a PHP API, and flat-file or database storage.

## Requirements

- Statamic 6 **Pro**
- PHP 8.3 or later
- Revisions turned on, for the site and for each collection that needs sign-off
