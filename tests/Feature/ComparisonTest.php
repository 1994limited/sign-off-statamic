<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NineteenNinetyFour\SignOff\Comparison;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;

/**
 * Comparing what's proposed with what's live, or with the last round.
 */
class ComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Blueprint::make('pages')->setNamespace('collections.pages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
            ['handle' => 'body', 'field' => ['type' => 'markdown', 'display' => 'Body']],
            ['handle' => 'content', 'field' => ['type' => 'bard', 'display' => 'Content', 'container' => 'assets']],
            ['handle' => 'related', 'field' => ['type' => 'entries', 'display' => 'Related']],
            ['handle' => 'featured', 'field' => ['type' => 'toggle', 'display' => 'Featured']],
            ['handle' => 'tone', 'field' => ['type' => 'select', 'display' => 'Tone', 'options' => ['plain' => 'Plain English', 'formal' => 'Formal']]],
            ['handle' => 'intro', 'field' => ['type' => 'section', 'display' => 'Intro']],
            ['handle' => 'hero', 'field' => ['type' => 'assets', 'display' => 'Hero image', 'container' => 'assets', 'max_files' => 1]],
            ['handle' => 'gallery', 'field' => ['type' => 'assets', 'display' => 'Gallery', 'container' => 'assets']],
        ]]]]]])->save();
    }

    protected function tearDown(): void
    {
        // Saved blueprints are files; don't leave this one for other tests.
        File::delete(resource_path('blueprints/collections/pages/pages.yaml'));

        parent::tearDown();
    }

    protected function compare($entry, ?string $against = null): array
    {
        return app(Comparison::class)->for($this->fresh($entry), $against);
    }

    protected function field(array $comparison, string $handle): ?array
    {
        return collect($comparison['fields'])->firstWhere('handle', $handle);
    }

    #[Test]
    public function a_live_page_is_compared_with_its_working_copy(): void
    {
        $page = $this->changedLivePage('Contact', ['body' => 'New opening hours.', 'featured' => true]);

        $comparison = $this->compare($page);

        $this->assertSame('live', $comparison['against']);
        $this->assertSame('Live', $comparison['before_label']);
        $this->assertSame(2, $comparison['changed']);
        $this->assertSame(['handle' => 'body', 'display' => 'Body', 'before' => 'Old opening hours.', 'after' => 'New opening hours.', 'changed' => true, 'media' => null], $this->field($comparison, 'body'));
        $this->assertSame([null, 'Yes'], [$this->field($comparison, 'featured')['before'], $this->field($comparison, 'featured')['after']]);
        $this->assertFalse($this->field($comparison, 'title')['changed']);
        $this->assertNull($this->field($comparison, 'intro'), 'Sections have no value to compare.');
    }

    #[Test]
    public function a_new_page_shows_everything_as_new(): void
    {
        $comparison = $this->compare($this->draft());

        $this->assertSame('Not published yet', $comparison['before_label']);
        $this->assertSame(['title' => 'About us', 'body' => 'First draft.', 'slug' => 'about-us'], collect($comparison['fields'])->filter(fn ($field) => $field['changed'])->pluck('after', 'handle')->all());
        $this->assertNull($this->field($comparison, 'body')['before']);
    }

    #[Test]
    public function values_are_shown_as_people_know_them(): void
    {
        $other = Entry::make()->collection('pages')->slug('returns')->data(['title' => 'Returns']);
        $other->save();

        $page = $this->draft();
        $page->merge([
            'related' => [$other->id(), 'gone'],
            'tone' => 'plain',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Delivery']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'We post '], ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'daily'], ['type' => 'text', 'text' => '.']]],
                ['type' => 'bulletList', 'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'UK']]]]],
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Europe']]]]],
                ]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'callout', 'text' => 'Free over £40']]],
            ],
        ])->save();

        $comparison = $this->compare($page);

        $this->assertSame("Returns\ngone", $this->field($comparison, 'related')['after']);
        $this->assertSame('Plain English', $this->field($comparison, 'tone')['after']);
        $this->assertSame("## Delivery\n\nWe post daily.\n\n- UK\n- Europe\n[callout]\ntext: 'Free over £40'", $this->field($comparison, 'content')['after']);
    }

    #[Test]
    public function after_being_sent_back_it_can_be_compared_with_what_was_submitted_before(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Shorter.');

        $this->fresh($page)->set('body', 'Short.')->save();

        // Before resubmitting: the author's changes since.
        $comparison = $this->compare($page, 'previous');
        $this->assertSame('previous', $comparison['against']);
        $this->assertSame(['First draft.', 'Short.'], [$this->field($comparison, 'body')['before'], $this->field($comparison, 'body')['after']]);
        $this->assertSame(1, $comparison['changed']);

        // In review again: the reviewer sees the same.
        $this->signOff()->submit($this->author(), $this->fresh($page));
        $comparison = $this->compare($page, 'previous');
        $this->assertSame('In review', $comparison['after_label']);
        $this->assertSame(['First draft.', 'Short.'], [$this->field($comparison, 'body')['before'], $this->field($comparison, 'body')['after']]);
    }

    #[Test]
    public function only_the_latest_rounds_keep_their_content(): void
    {
        $page = $this->draft();

        foreach (['Two.', 'Three.'] as $body) {
            $this->signOff()->submit($this->author(), $this->fresh($page));
            $this->signOff()->reject($this->lawyer(), $page, 'Again.');
            $this->fresh($page)->set('body', $body)->save();
        }
        $this->signOff()->submit($this->author(), $this->fresh($page));

        $this->assertSame([true, true, false], $this->signOff()->history($page)->map(fn ($round) => $round->snapshot() !== null)->all());
    }

    #[Test]
    public function the_comparison_needs_permission_to_see_the_entry(): void
    {
        $page = $this->changedLivePage();

        $this->actingAs($this->user('reporter', 'reporter'))->getJson(cp_route('sign-off.compare', $page->id()))->assertForbidden();
        $this->actingAs($this->lawyer())->getJson(cp_route('sign-off.compare', $page->id()))
            ->assertOk()
            ->assertJsonPath('changed', 1)
            ->assertJsonPath('options.live', 'Live version');
    }

    /**
     * An images container with a few pictures and a PDF in it.
     */
    protected function assets(): void
    {
        Storage::fake('assets');
        AssetContainer::make('assets')->disk('assets')->save();

        foreach (['old-hero.jpg', 'new-hero.jpg', 'a.jpg', 'b.jpg', 'c.jpg'] as $name) {
            Storage::disk('assets')->put($name, UploadedFile::fake()->image($name, 40, 30)->getContent());
        }
        Storage::disk('assets')->put('prices.pdf', '%PDF-1.4');
        Storage::disk('assets')->put('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        Asset::find('assets::new-hero.jpg')->set('alt', 'The turbines at dusk')->save();
    }

    #[Test]
    public function a_replaced_image_is_shown_next_to_its_replacement(): void
    {
        $this->assets();
        $page = $this->changedLivePage('Home', ['hero' => 'new-hero.jpg']);
        $this->fresh($page)->set('hero', 'old-hero.jpg')->save();

        $media = $this->field($this->compare($page), 'hero')['media'];

        $this->assertTrue($media['single']);
        $this->assertSame('assets', $media['kind']);
        $this->assertSame(['old-hero.jpg', 'removed'], [$media['before'][0]['name'], $media['before'][0]['status']]);
        $this->assertSame(['new-hero.jpg', 'added', 'The turbines at dusk', true], [$media['after'][0]['name'], $media['after'][0]['status'], $media['after'][0]['alt'], $media['after'][0]['is_image']]);
        $this->assertStringContainsString('/sign-off/compare/', $media['after'][0]['thumbnail']);
        $this->assertStringEndsWith('/small', $media['after'][0]['thumbnail']);
        $this->assertNotSame($media['after'][0]['thumbnail'], $media['after'][0]['full']);
    }

    #[Test]
    public function a_gallery_shows_what_was_added_removed_and_kept(): void
    {
        $this->assets();
        $page = $this->changedLivePage('Home', ['gallery' => ['b.jpg', 'c.jpg', 'prices.pdf', 'deleted.jpg']]);
        $this->fresh($page)->set('gallery', ['a.jpg', 'b.jpg'])->save();

        $media = $this->field($this->compare($page), 'gallery')['media'];

        $this->assertFalse($media['single']);
        $this->assertSame(['a.jpg' => 'removed', 'b.jpg' => 'same'], array_column($media['before'], 'status', 'name'));
        $this->assertSame(['b.jpg' => 'same', 'c.jpg' => 'added', 'prices.pdf' => 'added', 'deleted.jpg' => 'added'], array_column($media['after'], 'status', 'name'));

        [, , $pdf, $gone] = $media['after'];
        $this->assertFalse($pdf['is_image']);
        $this->assertNull($pdf['thumbnail']);
        $this->assertNotNull($pdf['url']);
        $this->assertTrue($gone['missing']);
    }

    #[Test]
    public function images_in_bard_are_shown_too(): void
    {
        $this->assets();
        $image = fn ($src, $alt = null) => ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => $alt]];
        $page = $this->changedLivePage('Home', ['content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Our sites.']]],
            $image('asset::assets::new-hero.jpg', 'Breakwater Farm'),
            $image('https://example.org/map.png', 'A map'),
        ]]);
        $this->fresh($page)->set('content', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Our sites.']]]])->save();

        $row = $this->field($this->compare($page), 'content');

        $this->assertSame('bard', $row['media']['kind']);
        $this->assertSame([], $row['media']['before']);
        $this->assertSame(['new-hero.jpg', 'map.png'], array_column($row['media']['after'], 'name'));
        $this->assertSame(['Breakwater Farm', 'A map'], array_column($row['media']['after'], 'alt'));
        // An image linked by URL is offered as a link, never loaded.
        $this->assertNull($row['media']['after'][1]['full']);
        $this->assertTrue($row['media']['after'][1]['linked']);
        $this->assertSame('https://example.org/map.png', $row['media']['after'][1]['url']);
        $this->assertTrue($row['media']['after'][0]['previewable']);
        $this->assertStringContainsString('[Image: new-hero.jpg]', $row['after']);
    }

    #[Test]
    public function fields_without_images_have_no_previews(): void
    {
        $this->assets();

        $this->assertNull($this->field($this->compare($this->changedLivePage()), 'hero')['media']);
    }

    #[Test]
    public function reviewers_see_the_entrys_images_without_access_to_the_assets(): void
    {
        $this->assets();
        $page = $this->changedLivePage('Home', ['hero' => 'new-hero.jpg', 'gallery' => ['prices.pdf', 'logo.svg']]);
        $this->fresh($page)->set('hero', 'old-hero.jpg')->save();

        $url = fn ($id, $size = null) => cp_route('sign-off.preview', array_filter(['entry' => $page->id(), 'file' => rtrim(strtr(base64_encode($id), '+/', '-_'), '='), 'size' => $size]));

        // The lawyer can't see the assets container, but can review the page.
        $this->assertFalse($this->lawyer()->can('view', Asset::find('assets::new-hero.jpg')));
        $this->actingAs($this->lawyer())->get($url('assets::new-hero.jpg', 'small'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->lawyer())->get($url('assets::old-hero.jpg'))->assertOk();

        // Files the page doesn't use aren't served.
        $this->actingAs($this->lawyer())->get($url('assets::a.jpg'))->assertNotFound();
        $this->actingAs($this->lawyer())->get(cp_route('sign-off.preview', ['entry' => $page->id(), 'file' => 'not-base64!']))->assertNotFound();

        // Nor to people who can't see the page.
        $this->actingAs($this->user('reporter', 'reporter'))->get($url('assets::new-hero.jpg'))->assertRedirect();

        // Other files are sent as they are, unable to run scripts.
        $this->actingAs($this->lawyer())->get($url('assets::prices.pdf'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $svg = $this->actingAs($this->lawyer())->get($url('assets::logo.svg'))->assertOk();
        $this->assertStringContainsString('sandbox', $svg->headers->get('Content-Security-Policy'));
    }

    #[Test]
    public function previews_only_come_from_the_fields_own_container(): void
    {
        $this->assets();
        Storage::fake('private');
        AssetContainer::make('private')->disk('private')->save();
        Storage::disk('private')->put('salaries.pdf', '%PDF-1.4 secret');

        // Saved into the entry by hand: a file from a container the field doesn't use.
        $page = $this->changedLivePage('Home', [
            'gallery' => ['private::salaries.pdf'],
            'content' => [['type' => 'image', 'attrs' => ['src' => 'asset::private::salaries.pdf']]],
        ]);

        $comparison = $this->compare($page);
        $gallery = $this->field($comparison, 'gallery')['media']['after'][0];
        $this->assertFalse($gallery['previewable']);
        $this->assertTrue($gallery['elsewhere']);
        $this->assertNull($gallery['url']);
        $this->assertFalse($this->field($comparison, 'content')['media']['after'][0]['previewable']);

        $url = cp_route('sign-off.preview', ['entry' => $page->id(), 'file' => rtrim(strtr(base64_encode('private::salaries.pdf'), '+/', '-_'), '=')]);
        $this->actingAs($this->lawyer())->get($url)->assertNotFound();
        $this->actingAs($this->admin())->get($url)->assertNotFound();
    }

    #[Test]
    public function bard_without_a_container_has_no_previews(): void
    {
        $this->assets();
        Blueprint::find('collections.pages.pages')->ensureFieldHasConfig('content', ['container' => null])->save();

        $page = $this->changedLivePage('Home', ['content' => [['type' => 'image', 'attrs' => ['src' => 'asset::assets::a.jpg']]]]);

        $this->assertFalse($this->field($this->compare($page), 'content')['media']['after'][0]['previewable']);
    }

    #[Test]
    public function previews_are_private_and_files_download(): void
    {
        $this->assets();
        $page = $this->changedLivePage('Home', ['hero' => 'new-hero.jpg', 'gallery' => ['prices.pdf', 'logo.svg']]);
        $url = fn ($id) => cp_route('sign-off.preview', ['entry' => $page->id(), 'file' => rtrim(strtr(base64_encode($id), '+/', '-_'), '=')]);

        $image = $this->actingAs($this->lawyer())->get($url('assets::new-hero.jpg'))->assertOk();
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));

        $pdf = $this->actingAs($this->lawyer())->get($url('assets::prices.pdf'))->assertOk();
        $this->assertStringStartsWith('attachment;', $pdf->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $pdf->headers->get('Content-Type'));

        $svg = $this->actingAs($this->lawyer())->get($url('assets::logo.svg'))->assertOk();
        $this->assertStringStartsWith('inline;', $svg->headers->get('Content-Disposition'));
        $this->assertSame('image/svg+xml', $svg->headers->get('Content-Type'));
    }
}
