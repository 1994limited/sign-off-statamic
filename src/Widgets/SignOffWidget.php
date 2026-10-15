<?php

namespace NineteenNinetyFour\SignOff\Widgets;

use NineteenNinetyFour\SignOff\Reviews;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

/**
 * The dashboard's "Sign-off" widget: what's waiting for you, what was sent
 * back to you, and your entries in review. Add it to config/statamic/cp.php:
 *
 *     'widgets' => [
 *         ['type' => 'sign_off', 'width' => 50],
 *     ],
 *
 * Options: title, limit (5).
 */
class SignOffWidget extends Widget
{
    protected static $handle = 'sign_off';

    public function component()
    {
        $user = User::current();
        $reviews = app(Reviews::class);

        if (! $user || $reviews->collectionsFor($user) === []) {
            return;
        }

        $limit = max(1, (int) $this->config('limit', 5));
        $waiting = $reviews->waitingFor($user);

        return VueComponent::render('sign-off-widget', [
            'title' => $this->config('title', __('Sign-off')),
            'waiting' => $reviews->rows($waiting->take($limit), $user),
            'waitingTotal' => $waiting->count(),
            'sentBack' => $reviews->rows($reviews->sentBackTo($user, $limit), $user),
            'yours' => $reviews->rows($reviews->submittedBy($user, $limit), $user),
            'url' => cp_route('sign-off.index'),
        ]);
    }
}
