# Sign-off documentation

Sign-off adds approval steps to Statamic Pro. An editor submits an entry, it goes through each step in turn, and the last approval publishes it.

## Getting started

- [Installation](installation.md): requirements, setting up your first collection, the widget, email and storage.
- [Configuration](configuration.md): every option in `config/sign-off.php`, and who can approve a step.

## Using it

- [How sign-off works](how-it-works.md): an entry's way through review, the lock, sending back and withdrawing, skipping sign-off, taking pages down, and what sign-off doesn't cover.
- [The Control Panel](control-panel.md): the Sign-off panel, the actions, comparing changes, the review page, the dashboard widget and permissions.
- [Emails](emails.md): who is told what, and using other channels.

## For developers

- [Events and the PHP API](php-api.md): listening for each step, and submitting, approving and reading history from your own code.
- [Troubleshooting](troubleshooting.md): `php please sign-off:check`, and answers to common questions.

## At a glance

| | |
| --- | --- |
| Needs | Statamic 6 Pro, PHP 8.3+, revisions on |
| Steps | Per collection, any number, in order |
| Approvers | Roles, user groups, users, a permission, or anyone who can publish |
| Storage | Flat files (default) or the database |
| Commands | `php please sign-off:check` |
| Permission | Publish without sign-off |
| Widget | `sign_off` |
