<?php

namespace NineteenNinetyFour\SignOff;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use NineteenNinetyFour\SignOff\Listeners\AddPanel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Term;
use Statamic\Facades\User as Users;
use Statamic\Facades\YAML;
use Statamic\Fields\Field;

/**
 * What would change, field by field: the proposed content (the working
 * copy, or a new entry) next to the live version, or next to what was
 * submitted last time. Values are turned into readable text here; the
 * Control Panel highlights the words that differ.
 */
class Comparison
{
    /** Field types with nothing to compare. */
    protected const SKIP = ['section', 'spacer', 'html', 'revealer', 'hidden', 'sign_off'];

    /** The entry being compared, for its preview URLs. */
    protected ?Entry $entry = null;

    public function __construct(protected SignOff $signOff) {}

    /**
     * What the proposed content can be compared with.
     *
     * @return array<string, string> handle => label
     */
    public function options(Entry $entry): array
    {
        $options = ['live' => $entry->published() ? __('Live version') : __('Nothing (not published yet)')];

        if ($previous = $this->previous($entry)) {
            $options['previous'] = __('As submitted :when', ['when' => $previous->submittedAt()?->diffForHumans() ?? '']);
        }

        return $options;
    }

    /**
     * @return array{against: string, options: array<string, string>, before_label: string, after_label: string, fields: array<int, array<string, mixed>>, changed: int}
     */
    public function for(Entry $entry, ?string $against = null): array
    {
        $this->entry = $entry;
        $options = $this->options($entry);
        $against = array_key_exists((string) $against, $options) ? $against : 'live';

        $after = Fingerprint::content($entry);
        $before = match ($against) {
            'previous' => $this->previous($entry)->snapshot(),
            default => $entry->published() ? Fingerprint::read($entry) : null,
        };

        $fields = $this->fields($entry, $before, $after);

        return [
            'against' => $against,
            'options' => $options,
            'before_label' => match (true) {
                $against === 'previous' => __('Submitted before'),
                $before === null => __('Not published yet'),
                default => __('Live'),
            },
            'after_label' => $this->signOff->open($entry) ? __('In review') : __('Proposed'),
            'fields' => $fields,
            'changed' => count(array_filter($fields, fn (array $field) => $field['changed'])),
        ];
    }

    /**
     * Whether the asset is in what the entry's comparison shows: the live
     * content, the proposed content, or the last submission.
     */
    public function shows(Entry $entry, string $assetId): bool
    {
        return collect(array_keys($this->options($entry)))
            ->flatMap(fn (string $against) => collect($this->for($entry, $against)['fields'])->pluck('media')->filter())
            ->flatMap(fn (array $media) => [...$media['before'], ...$media['after']])
            ->contains(fn (array $file) => $file['key'] === $assetId);
    }

    /**
     * The latest earlier round that still has its content: in review, the
     * one before; otherwise the last one submitted.
     */
    public function previous(Entry $entry): ?Submission
    {
        $history = $this->signOff->history($entry);
        $rounds = $history->first()?->isOpen() ? $history->slice(1) : $history;

        return $rounds->first(fn (Submission $round) => $round->snapshot() !== null);
    }

    /**
     * @return array<int, array{handle: string, display: string, before: ?string, after: ?string, changed: bool}>
     */
    protected function fields(Entry $entry, ?array $before, array $after): array
    {
        $rows = [];

        foreach ($entry->blueprint()->fields()->all() as $handle => $field) {
            if (in_array($field->type(), self::SKIP, true) || $handle === AddPanel::HANDLE || $field->visibility() === 'computed') {
                continue;
            }

            $old = $this->value($handle, $before);
            $new = $this->value($handle, $after);

            $rows[] = [
                'handle' => $handle,
                'display' => __($field->display()),
                'before' => $before === null ? null : $this->text($field, $old, $handle),
                'after' => $this->text($field, $new, $handle),
                'changed' => $before === null ? $this->filled($new) : json_encode($old) !== json_encode($new),
                'media' => $this->media($field, $before === null ? null : $old, $new),
            ];
        }

        return $rows;
    }

    /**
     * Images and files on each side, for previews: an assets field's
     * files, or the images in a Bard field. Null when there are none.
     *
     * @return array{before: array<int, array<string, mixed>>, after: array<int, array<string, mixed>>, kind: string, single: bool}|null
     */
    protected function media(Field $field, mixed $old, mixed $new): ?array
    {
        $items = match ($field->type()) {
            'assets' => fn ($value) => array_map(fn ($path) => $this->file($this->assetId($field, $path)), array_values(array_filter(Arr::wrap($value), 'is_string'))),
            'bard' => fn ($value) => is_array($value) ? array_map(fn ($image) => $this->file($image['src'], $image['alt']), $this->images($value)) : [],
            default => null,
        };

        if (! $items) {
            return null;
        }

        $before = $items($old);
        $after = $items($new);

        if ($before === [] && $after === []) {
            return null;
        }

        $kept = array_intersect(array_column($before, 'key'), array_column($after, 'key'));
        $mark = fn (array $files, string $status) => array_map(fn ($file) => $file + ['status' => in_array($file['key'], $kept, true) ? 'same' : $status], $files);

        return [
            'before' => $mark($before, 'removed'),
            'after' => $mark($after, 'added'),
            // Files are the whole value; Bard's images sit in its text.
            'kind' => $field->type(),
            // One file each side: a change is the one replacing the other.
            'single' => $field->type() === 'assets' && $field->get('max_files') === 1,
        ];
    }

    protected function assetId(Field $field, string $path): string
    {
        if (str_contains($path, '::')) {
            return $path;
        }

        $container = $field->get('container') ?? AssetContainer::all()->first()?->handle();

        return "{$container}::{$path}";
    }

    /**
     * An asset, or an image by URL, as a preview.
     */
    protected function file(string $reference, ?string $alt = null): array
    {
        $id = str_starts_with($reference, 'asset::') ? substr($reference, 7) : $reference;
        $asset = str_contains($id, '::') ? Asset::find($id) : null;

        if ($asset) {
            $image = $asset->isImage() || $asset->isSvg();
            // Through Sign-off, so anyone who can review the entry can see
            // its images, without needing access to the asset container.
            $url = fn (?string $size = null) => cp_route('sign-off.preview', array_filter([
                'entry' => $this->entry?->id(),
                'file' => rtrim(strtr(base64_encode($asset->id()), '+/', '-_'), '='),
                'size' => $size,
            ]));

            return [
                'key' => $asset->id(),
                'name' => $asset->basename(),
                'is_image' => $image,
                'thumbnail' => $image ? $url('small') : null,
                'full' => $image ? $url() : null,
                'url' => $image ? null : $url(),
                'alt' => $alt ?? $asset->get('alt'),
                'missing' => false,
            ];
        }

        // An image linked by its URL, or an asset that's gone.
        $isUrl = (bool) preg_match('#^(https?:)?//|^/#', $reference);

        return [
            'key' => $reference,
            'name' => $this->fileName($reference),
            'is_image' => $isUrl,
            'thumbnail' => $isUrl ? $reference : null,
            'full' => $isUrl ? $reference : null,
            'url' => null,
            'alt' => $alt,
            'missing' => ! $isUrl,
        ];
    }

    /**
     * A file's name from its reference: "asset::assets::photos/hero.jpg",
     * "assets::hero.jpg" or a URL.
     */
    protected function fileName(string $reference): string
    {
        $path = str_contains($reference, '::') ? Str::afterLast($reference, '::') : (parse_url($reference, PHP_URL_PATH) ?: $reference);

        return basename($path) ?: $reference;
    }

    /**
     * The images in Bard's content, in order.
     *
     * @return array<int, array{src: string, alt: ?string}>
     */
    protected function images(array $nodes): array
    {
        $images = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'image' && is_string($src = $node['attrs']['src'] ?? null) && $src !== '') {
                $images[] = ['src' => $src, 'alt' => is_string($node['attrs']['alt'] ?? null) ? $node['attrs']['alt'] : null];
            }

            $images = [...$images, ...$this->images((array) ($node['content'] ?? []))];
        }

        return $images;
    }

    protected function value(string $handle, ?array $content): mixed
    {
        if ($content === null) {
            return null;
        }

        return match ($handle) {
            'slug' => $content['slug'] ?? null,
            'date' => $content['date'] ?? null,
            default => $content['data'][$handle] ?? null,
        };
    }

    protected function filled(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    /**
     * A value as text a reviewer can read.
     */
    public function text(Field $field, mixed $value, ?string $handle = null): ?string
    {
        if (! $this->filled($value)) {
            return null;
        }

        if ($handle === 'date' && is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value)->format('j M Y, H:i');
        }

        return match ($field->type()) {
            'bard' => is_array($value) ? trim($this->bard($value)) : (string) $value,
            'toggle' => $value ? __('Yes') : __('No'),
            'entries' => $this->list($value, fn ($id) => ($entry = Entries::find($id)) ? (string) ($entry->value('title') ?? $entry->slug()) : $id),
            'terms' => $this->list($value, fn ($id) => ($term = Term::find(str_contains($id, '::') ? $id : (($field->get('taxonomies')[0] ?? '').'::'.$id))) ? (string) $term->title() : $id),
            'users' => $this->list($value, fn ($id) => ($user = Users::find($id)) ? ($user->name() ?: $user->email()) : $id),
            'select', 'checkboxes', 'radio', 'button_group' => $this->list($value, fn ($option) => $this->optionLabel($field, $option)),
            'code' => is_array($value) ? (string) ($value['code'] ?? '') : (string) $value,
            default => is_scalar($value) ? (string) $value : trim(YAML::dump($value)),
        };
    }

    protected function list(mixed $value, callable $name): string
    {
        return collect((array) $value)->map(fn ($item) => is_scalar($item) ? $name((string) $item) : trim(YAML::dump($item)))->implode("\n");
    }

    protected function optionLabel(Field $field, string $value): string
    {
        $options = (array) $field->get('options', []);

        if (array_is_list($options)) {
            foreach ($options as $option) {
                if (is_array($option) && (string) ($option['key'] ?? '') === $value) {
                    return (string) ($option['value'] ?? $value);
                }
            }

            return $value;
        }

        return (string) ($options[$value] ?? $value);
    }

    /**
     * Bard's content as text: a line per block, headings and list items
     * marked as in Markdown, and sets by their type and values.
     */
    protected function bard(array $nodes, string $prefix = ''): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';
            $content = (array) ($node['content'] ?? []);

            $text .= match ($type) {
                'text' => (string) ($node['text'] ?? ''),
                'hardBreak' => "\n",
                'paragraph' => $prefix.$this->bard($content)."\n\n",
                'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 2)).' '.$this->bard($content)."\n\n",
                'bulletList' => $this->bard($content, '- '),
                'orderedList' => $this->bard($content, '1. '),
                'listItem' => $prefix.trim($this->bard($content))."\n",
                'blockquote' => '> '.trim($this->bard($content))."\n\n",
                'image' => '['.__('Image').': '.$this->fileName((string) ($node['attrs']['src'] ?? '')).']'."\n\n",
                'horizontalRule' => "---\n\n",
                'set' => $this->set((array) ($node['attrs']['values'] ?? [])),
                default => $this->bard($content, $prefix),
            };
        }

        return $text;
    }

    protected function set(array $values): string
    {
        $type = (string) ($values['type'] ?? __('set'));
        unset($values['type']);

        return '['.$type.']'.($values ? "\n".trim(YAML::dump($values)) : '')."\n\n";
    }
}
