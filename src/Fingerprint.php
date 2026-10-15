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
        $content = $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : $entry;

        return hash('sha256', json_encode(static::sorted([
            'slug' => $content->slug(),
            'date' => $content->collection()?->dated() ? $content->date()?->timestamp : null,
            'data' => $content->data()->except(['updated_by', 'updated_at'])->all(),
        ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
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
