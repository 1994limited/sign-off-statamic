<?php

namespace NineteenNinetyFour\SignOff;

use Statamic\Contracts\Entries\Entry;

/**
 * A hash of the content that would go live: the working copy when there
 * is one, or the entry itself (an entry that was never published). Taken
 * when an entry is submitted, so a change after that can be spotted.
 */
class Fingerprint
{
    public static function of(Entry $entry): string
    {
        return static::hash(static::content($entry));
    }

    public static function hash(array $content): string
    {
        return hash('sha256', json_encode(static::sorted($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The content that would go live: slug, date and field values. Kept
     * with each submission, so the next round can be compared with it.
     *
     * @return array{slug: ?string, date: ?int, data: array<string, mixed>}
     */
    public static function content(Entry $entry): array
    {
        return static::read($entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry);
    }

    /**
     * The same, from exactly this entry object (for the live version, the
     * entry without its working copy).
     */
    public static function read(Entry $content): array
    {
        return static::sorted([
            'slug' => $content->slug(),
            'date' => $content->collection()?->dated() ? $content->date()?->timestamp : null,
            // Not content: who saved it last and when, and which blueprint
            // (written into the file on save, so a just-made entry lacks it).
            'data' => $content->data()->except(['updated_by', 'updated_at', 'blueprint'])->all(),
        ]);
    }

    protected static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => static::sorted($item), $value);
    }
}
