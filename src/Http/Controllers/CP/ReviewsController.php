<?php

namespace NineteenNinetyFour\SignOff\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use League\Glide\Server;
use NineteenNinetyFour\SignOff\Comparison;
use NineteenNinetyFour\SignOff\Reviews;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset as Assets;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Imaging\ImageGenerator;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
        $problems = $reviews->problemsFor($user);

        abort_if($collections === [] && $problems === [], 403);

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
            'problems' => $problems,
            'urls' => ['index' => cp_route('sign-off.index')],
        ]);
    }

    /**
     * An entry's proposed content next to the live version (or what was
     * submitted last time), for the Sign-off panel.
     */
    public function compare(Request $request, Entry $entry, Comparison $comparison): JsonResponse
    {
        $this->authorize('view', $entry);

        return response()->json($comparison->for($entry, $request->query('against')));
    }

    /**
     * An image or file in an entry's comparison, for anyone who can view
     * the entry: reviewers may not have access to the asset container.
     * Only files the comparison shows are served.
     */
    public function preview(Comparison $comparison, ImageGenerator $images, Server $glide, Entry $entry, string $file, ?string $size = null): SymfonyResponse
    {
        $this->authorize('view', $entry);

        $id = base64_decode(strtr($file, '-_', '+/'), true);

        abort_unless(is_string($id) && $comparison->shows($entry, $id) && ($asset = Assets::find($id)), 404);

        // Raster images through Glide, as Statamic's own thumbnails are.
        if ($asset->isImage() && ! $asset->isSvg()) {
            $path = $images->generateByAsset($asset, $size === 'small' ? ['p' => 'cp_thumbnail_small_landscape'] : []);

            return $this->private($glide->getResponseFactory()->create($glide->getCache(), $path));
        }

        // An SVG is shown in an <img>, which runs no scripts; opened on its
        // own it's sandboxed. Anything else (a PDF, a document) downloads,
        // rather than opening on the Control Panel's site.
        $inline = $asset->isSvg();

        return $this->private(response()->stream(fn () => fpassthru($asset->disk()->filesystem()->readStream($asset->path())), 200, [
            'Content-Type' => $inline ? 'image/svg+xml' : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($asset->basename(), '"\\').'"',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /**
     * Previews can be of private files: never kept by shared caches.
     */
    protected function private(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->remove('Expires');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    protected function paginate($items, int $page, int $perPage = 25): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page);
    }
}
