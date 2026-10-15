<?php

namespace NineteenNinetyFour\SignOff;

use Illuminate\Support\Arr;
use Statamic\Contracts\Addons\SettingsRepository;
use Statamic\Facades\Addon;

/**
 * Sign-off's settings page in the Control Panel (Tools → Addons → Sign-off
 * → Settings): the steps for each collection, the rules and email.
 *
 * Saved settings (resources/addons/sign-off-statamic.yaml) are used
 * instead of the same options in config/sign-off.php. Until they're first
 * saved, the form shows what the config file says, so saving it doesn't
 * lose anything. Storage stays in the config file.
 */
class ControlPanelSettings
{
    public const PACKAGE = '1994/sign-off-statamic';

    /** The options the page can set, as config keys. */
    public const RULES = ['self_approval', 'lock_during_review', 'super_users_can_skip', 'unpublish_without_sign_off'];

    /**
     * The settings saved in the Control Panel, or null when there are none.
     */
    public function saved(): ?array
    {
        $addon = Addon::get(self::PACKAGE);

        // As saved: all() fills gaps from the page's defaults, which come
        // from the config file, so an emptied list would come back.
        return $addon ? app(SettingsRepository::class)->find($addon->id())?->raw() : null;
    }

    /**
     * Use the saved settings in place of the config file's.
     */
    public function apply(): void
    {
        if (($saved = $this->saved()) === null) {
            return;
        }

        $config = [];

        // An empty list isn't saved at all, and means no collections.
        $config['sign-off.collections'] = $this->collections((array) ($saved['collections'] ?? []));

        foreach (self::RULES as $rule) {
            if (array_key_exists($rule, $saved)) {
                $config["sign-off.{$rule}"] = (bool) $saved[$rule];
            }
        }

        if (array_key_exists('mail', $saved)) {
            $config['sign-off.notifications.mail'] = (bool) $saved['mail'];
        }

        config($config);
    }

    /**
     * The settings page's rows, as config/sign-off.php's collections.
     */
    public function collections(array $rows): array
    {
        $collections = [];

        foreach ($rows as $row) {
            $handle = Arr::first(Arr::wrap($row['collection'] ?? null));

            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $collections[$handle] = ['steps' => array_values(array_map(fn (array $step) => array_filter([
                'handle' => (string) ($step['handle'] ?? ''),
                'title' => (string) ($step['title'] ?? ''),
                'approvers' => $this->approvers($step),
            ], fn ($value) => $value !== ''), array_filter((array) ($row['steps'] ?? []), 'is_array')))];
        }

        return $collections;
    }

    protected function approvers(array $step): string|array
    {
        $approvers = array_filter([
            'roles' => array_values(array_filter(Arr::wrap($step['roles'] ?? []))),
            'groups' => array_values(array_filter(Arr::wrap($step['groups'] ?? []))),
            'users' => array_values(array_filter(Arr::wrap($step['users'] ?? []))),
            'permission' => trim((string) ($step['permission'] ?? '')),
        ]);

        if (! empty($step['publishers'])) {
            if ($approvers === []) {
                return 'publishers';
            }

            $approvers['publishers'] = true;
        }

        return $approvers;
    }

    /**
     * Config's collections, as the settings page's rows: the form's
     * starting values until settings are first saved.
     */
    public function rows(array $collections): array
    {
        $rows = [];

        foreach ($collections as $handle => $config) {
            $rows[] = [
                'collection' => (string) $handle,
                'steps' => array_values(array_map(function ($step) {
                    $approvers = $step['approvers'] ?? 'publishers';
                    $approvers = $approvers === 'publishers' ? ['publishers' => true] : (array) $approvers;

                    return array_filter([
                        'title' => $step['title'] ?? null,
                        'handle' => $step['handle'] ?? null,
                        'publishers' => ! empty($approvers['publishers']),
                        'roles' => $approvers['roles'] ?? null,
                        'groups' => $approvers['groups'] ?? null,
                        'users' => $approvers['users'] ?? null,
                        'permission' => $approvers['permission'] ?? null,
                    ], fn ($value) => $value !== null);
                }, array_filter((array) ($config['steps'] ?? []), 'is_array'))),
            ];
        }

        return $rows;
    }

    /**
     * The settings page, starting from what the config file says.
     */
    public function blueprint(): array
    {
        $field = fn (string $handle, array $field) => ['handle' => $handle, 'field' => $field];

        return ['tabs' => ['main' => ['sections' => [
            [
                'display' => __('Collections that need sign-off'),
                'instructions' => __('Each collection’s steps, in the order they’re approved. Collections need revisions turned on. Collections not listed are published as usual.'),
                'fields' => [
                    $field('collections', [
                        'type' => 'grid',
                        'display' => __('Collections'),
                        'hide_display' => true,
                        'full_width_setting' => true,
                        'mode' => 'stacked',
                        'add_row' => __('Add a collection'),
                        'default' => $this->rows((array) config('sign-off.collections', [])),
                        'fields' => [
                            $field('collection', ['type' => 'collections', 'display' => __('Collection'), 'max_items' => 1, 'mode' => 'select', 'validate' => ['required']]),
                            $field('steps', [
                                'type' => 'grid',
                                'display' => __('Steps'),
                                'mode' => 'stacked',
                                'add_row' => __('Add a step'),
                                'min_rows' => 1,
                                'fields' => [
                                    $field('title', ['type' => 'text', 'display' => __('Title'), 'width' => 50, 'validate' => ['required']]),
                                    $field('handle', ['type' => 'slug', 'from' => 'title', 'separator' => '_', 'display' => __('Handle'), 'instructions' => __('Keep it the same once entries are in review.'), 'width' => 50, 'validate' => ['required', 'alpha_dash']]),
                                    $field('publishers', ['type' => 'toggle', 'display' => __('Anyone who can publish'), 'instructions' => __('Anyone with permission to publish the collection’s entries.')]),
                                    $field('roles', ['type' => 'user_roles', 'display' => __('Roles'), 'width' => 50]),
                                    $field('groups', ['type' => 'user_groups', 'display' => __('User groups'), 'width' => 50]),
                                    $field('users', ['type' => 'users', 'display' => __('Users'), 'mode' => 'select', 'width' => 50]),
                                    $field('permission', ['type' => 'text', 'display' => __('Permission'), 'instructions' => __('Users with this permission.'), 'width' => 50]),
                                ],
                            ]),
                        ],
                    ]),
                ],
            ],
            [
                'display' => __('Rules'),
                'fields' => [
                    $field('self_approval', ['type' => 'toggle', 'display' => __('Authors can approve their own entries'), 'default' => (bool) config('sign-off.self_approval', false)]),
                    $field('lock_during_review', ['type' => 'toggle', 'display' => __('Lock entries while they’re in review'), 'instructions' => __('Off: they can be edited, but any change sends them back to be submitted again.'), 'default' => (bool) config('sign-off.lock_during_review', true)]),
                    $field('super_users_can_skip', ['type' => 'toggle', 'display' => __('Super users can publish without sign-off'), 'instructions' => __('Other roles can be given the “Publish entries without sign-off” permission.'), 'default' => (bool) config('sign-off.super_users_can_skip', true)]),
                    $field('unpublish_without_sign_off', ['type' => 'toggle', 'display' => __('Anyone who can publish can unpublish'), 'instructions' => __('Off: only people who can publish without sign-off can take entries down.'), 'default' => (bool) config('sign-off.unpublish_without_sign_off', true)]),
                ],
            ],
            [
                'display' => __('Email'),
                'fields' => [
                    $field('mail', ['type' => 'toggle', 'display' => __('Email reviewers and authors'), 'instructions' => __('Reviewers when an entry reaches their step; authors when it’s sent back or signed off.'), 'default' => (bool) config('sign-off.notifications.mail', true)]),
                ],
            ],
        ]]]];
    }
}
