<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use NineteenNinetyFour\SignOff\Comparison;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
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
            ['handle' => 'content', 'field' => ['type' => 'bard', 'display' => 'Content']],
            ['handle' => 'related', 'field' => ['type' => 'entries', 'display' => 'Related']],
            ['handle' => 'featured', 'field' => ['type' => 'toggle', 'display' => 'Featured']],
            ['handle' => 'tone', 'field' => ['type' => 'select', 'display' => 'Tone', 'options' => ['plain' => 'Plain English', 'formal' => 'Formal']]],
            ['handle' => 'intro', 'field' => ['type' => 'section', 'display' => 'Intro']],
        ]]]]]])->save();
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
        $this->assertSame(['handle' => 'body', 'display' => 'Body', 'before' => 'Old opening hours.', 'after' => 'New opening hours.', 'changed' => true], $this->field($comparison, 'body'));
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
}
