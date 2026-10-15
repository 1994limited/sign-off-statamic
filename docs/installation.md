# Installation

## Requirements

- Statamic 6 **Pro**. Sign-off adds approval steps on top of Pro's revisions and publish permissions. Without Pro, it does nothing and entries are published as usual.
- PHP 8.3 or later.
- Revisions turned on for the site, and for each collection that needs sign-off.

## 1. Install the addon

```bash
composer require 1994/sign-off-statamic
php artisan vendor:publish --tag=sign-off-config
```

The second command copies the config to `config/sign-off.php`.

## 2. Turn on revisions

In `.env`:

```env
STATAMIC_REVISIONS_ENABLED=true
```

Then, for each collection that needs sign-off, turn on **Revisions** in the collection's settings in the Control Panel, or add this to its YAML file (for example `content/collections/pages.yaml`):

```yaml
revisions: true
```

With revisions on, changes to a published entry are saved to a working copy, and the live entry stays as it is until the working copy is published. Sign-off decides when that happens.

## 3. Add your steps

In `config/sign-off.php`, list each collection and the steps its entries go through, in order:

```php
'collections' => [

    'pages' => [
        'steps' => [
            ['handle' => 'legal', 'title' => 'Legal', 'approvers' => ['roles' => ['legal']]],
            ['handle' => 'publisher', 'title' => 'Publisher', 'approvers' => 'publishers'],
        ],
    ],

],
```

Here a page is approved by someone with the `legal` role, then by anyone who can publish pages, and that last approval publishes it. See [Configuration](configuration.md#who-can-approve-a-step) for every way to name approvers.

Each step needs a `handle` that stays the same: it's how a review in progress remembers which steps are done.

## 4. Check it

```bash
php please sign-off:check
```

```
  Collection ........................................... Steps
  pages .................................... Legal → Publisher

   INFO  Sign-off is set up and running.
```

If something is wrong (a collection without revisions, a role that doesn't exist) it says what, and exits with an error, so you can run it in your deploy. People who can configure collections see the same problems in the Control Panel.

## 5. Give people permission

Sign-off works with Statamic's own permissions:

- **Authors** need to be able to edit entries in the collection, to submit them.
- **Reviewers** need to be able to view the entries, and to match a step's approvers.
- To **publish without sign-off** (for urgent fixes), give a role the *Publish entries without sign-off* permission, under Sign-off in the role's settings. Super users have it unless you turn that off.

An author who can also publish no longer can, in a collection with steps: publishing is what the last approval does.

## 6. Add the dashboard widget (optional)

In `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'sign_off', 'width' => 50],
],
```

It shows each person what's waiting for their review, what was sent back to them, and their own entries in review. Options: `title`, and `limit` (5 per list).

## 7. Email (optional)

Sign-off emails reviewers when an entry reaches their step, and authors when it's sent back or signed off, using your site's mail settings. They're queued when your site has a queue worker. To turn them off:

```env
SIGN_OFF_MAIL=false
```

See [Emails](emails.md).

## Storing submissions in the database

By default, submissions and their history are YAML files in `storage/sign-off`, cached like entries. To keep them in the database instead:

```env
SIGN_OFF_DRIVER=database
```

```bash
php artisan migrate
```

The migration runs only with the database driver. To use another connection, set `SIGN_OFF_DB_CONNECTION`. To change the migration first, publish it with `php artisan vendor:publish --tag=sign-off-migrations`.

## Existing content

Nothing changes for entries until someone edits them. Published entries stay published. The next change to one is saved to its working copy, and has to be signed off before it goes live.
