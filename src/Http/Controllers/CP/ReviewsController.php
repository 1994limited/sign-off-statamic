<?php

namespace NineteenNinetyFour\SignOff\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\SignOff\Reviews;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Content → Sign-off: what's waiting for you, everything in review, and
 * what was signed off or sent back before.
 */
class ReviewsController extends CpController
{
    public const TABS = ['waiting', 'in_review', 'history'];

    public function index(Request $request, Reviews $reviews): Response
    {
        $user = User::current();
        $collections = $reviews->collectionsFor($user);

        abort_if($collections === [], 403);

        $collection = in_array($request->query('collection'), $collections, true) ? $request->query('collection') : null;
        $page = max(1, (int) $request->query('page', 1));

        $waiting = $reviews->waitingFor($user, $collection);
        $inReview = $reviews->inReview($user, $collection);

        // Start where there's something to do.
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : ($waiting->isNotEmpty() ? 'waiting' : 'in_review');

        $paginator = match ($tab) {
            'waiting' => $this->paginate($waiting, $page),
            'in_review' => $this->paginate($inReview, $page),
            'history' => $reviews->history($user, $page, $collection),
        };

        return Inertia::render('sign-off::Reviews', [
            'tab' => $tab,
            'counts' => ['waiting' => $waiting->count(), 'in_review' => $inReview->count()],
            'rows' => $reviews->rows($paginator->items(), $user),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'collection' => $collection,
            'collections' => count($collections) > 1 ? array_map(fn (string $handle) => [
                'value' => $handle,
                'label' => __(Collections::find($handle)->title()),
            ], $collections) : [],
            'urls' => ['index' => cp_route('sign-off.index')],
        ]);
    }

    protected function paginate($items, int $page, int $perPage = 25): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page);
    }
}
