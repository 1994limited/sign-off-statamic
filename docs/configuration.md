# Configuration

Every option lives in `config/sign-off.php`. Publish it with:

```bash
php artisan vendor:publish --tag=sign-off-config
```

## Collections and steps

```php
'collections' => [

    'pages' => [
        'steps' => [
            ['handle' => 'legal', 'title' => 'Legal', 'approvers' => ['roles' => ['legal']]],
            ['handle' => 'brand', 'title' => 'Brand', 'approvers' => ['groups' => ['marketing']]],
            ['handle' => 'publisher', 'title' => 'Publisher', 'approvers' => 'publishers'],
        ],
    ],

    'news' => [
        'steps' => [
            ['handle' => 'editor', 'title' => 'Editor', 'approvers' => ['permission' => 'approve news']],
        ],
    ],

],
```

Each collection lists its steps in the order they're approved. Collections that aren't listed work as they always have.

| Key | | |
| --- | --- | --- |
| `handle` | Required | Stays the same for the life of the step. A review in progress remembers which steps are done by their handles. |
| `title` | Optional | Shown in the Control Panel and in emails. Made from the handle if left out. |
| `approvers` | Optional | Who can approve it. `publishers` if left out. |

You can add, remove and reorder steps at any time. Entries already in review keep the steps they were submitted with, and the change applies from their next submission. If you remove a step while entries are waiting for it, super users can approve it, so those reviews can finish.

## Who can approve a step

| `approvers` | Who |
| --- | --- |
| `'publishers'` | Anyone with permission to publish the collection's entries |
| `['roles' => ['legal', 'compliance']]` | Users with any of these roles |
| `['groups' => ['marketing']]` | Members of any of these user groups |
| `['users' => ['sam@example.com', 'a1b2c3']]` | These users, by email address or ID |
| `['permission' => 'approve legal']` | Users with this permission |
| `['roles' => ['legal'], 'users' => ['sam@example.com']]` | Any of the above: mix them in one array |
| `['roles' => ['brand'], 'publishers' => true]` | Brand, or anyone who can publish |

**Super users** can approve any step, and their *Waiting for you* list includes everything they could approve. They're only emailed for steps nobody else can approve.

The person who submitted an entry can't approve any step of it, unless you allow it (below). They can still see where it is.

For a permission of your own, like `approve legal`, register it in a service provider so it can be given to roles in the Control Panel:

```php
use Statamic\Facades\Permission;

Permission::extend(function () {
    Permission::register('approve legal')->label('Approve the Legal step');
});
```

## Rules

| Option | Default | |
| --- | --- | --- |
| `self_approval` | `false` | Whether the person who submitted an entry can approve its steps. |
| `lock_during_review` | `true` | Nobody can edit an entry while it's in review. Turn it off and edits are allowed, but any change sends the entry back to its author to submit again, so approvals always match what goes live. |
| `super_users_can_skip` | `true` | Super users can publish without sign-off, as with the *Publish entries without sign-off* permission. Set to `false` and they go through sign-off like everyone else (they can still approve any step). |
| `unpublish_without_sign_off` | `true` | Taking a published entry down follows Statamic's usual publish permissions, so content can come down fast. Set to `false` and only people who can publish without sign-off can take entries down. |

## Storage

```php
'driver' => env('SIGN_OFF_DRIVER', 'file'),

'drivers' => [
    'file' => [
        'directory' => storage_path('sign-off'),
    ],
    'database' => [
        'connection' => env('SIGN_OFF_DB_CONNECTION'),
        'table' => 'sign_off_submissions',
    ],
],
```

Each round of review (a submission and what happened to it) is one record.

- **file** keeps them as YAML files in `storage/sign-off`, indexed and cached by the Stache like entries. `php please stache:clear` and `stache:warm` include them.
- **database** keeps them in one table. Run `php artisan migrate` after switching.

Each submission keeps a copy of the content it was submitted with, for [comparing changes](control-panel.md#comparing-changes). Only an entry's two latest rounds keep theirs.

## Email

```php
'notifications' => [
    'mail' => env('SIGN_OFF_MAIL', true),
],
```

See [Emails](emails.md).

## Translations

All text is in `lang/en/messages.php` in the package, and can be overridden like any Laravel package's: copy it to `lang/vendor/sign-off/en/messages.php` in your site and change what you like.
