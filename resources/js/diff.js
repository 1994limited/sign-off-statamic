// A word-level diff of two texts, for the comparison: which words were
// kept, removed and added. Words and the spaces between them are compared
// as they are; punctuation counts as a word of its own.

const LIMIT = 4_000_000; // cells in the table; past this, show a plain swap

export function tokens(text) {
    return (text ?? '').match(/\s+|[\p{Sc}\p{L}\p{N}_'’-]+|[^\s\p{L}\p{N}]/gu) ?? [];
}

export function diff(before, after) {
    const a = tokens(before);
    const b = tokens(after);

    // Shared start and end are kept as they are, and left out of the table.
    let start = 0;
    while (start < a.length && start < b.length && a[start] === b[start]) start++;
    let end = 0;
    while (end < a.length - start && end < b.length - start && a[a.length - 1 - end] === b[b.length - 1 - end]) end++;

    const head = a.slice(0, start);
    const tail = a.slice(a.length - end);
    const x = a.slice(start, a.length - end);
    const y = b.slice(start, b.length - end);

    const middle = x.length * y.length > LIMIT
        ? [...x.map((text) => ({ type: 'removed', text })), ...y.map((text) => ({ type: 'added', text }))]
        : lcs(x, y);

    return merge([
        ...head.map((text) => ({ type: 'same', text })),
        ...middle,
        ...tail.map((text) => ({ type: 'same', text })),
    ]);
}

function lcs(x, y) {
    const rows = x.length + 1;
    const cols = y.length + 1;
    const table = new Uint32Array(rows * cols);

    for (let i = x.length - 1; i >= 0; i--) {
        for (let j = y.length - 1; j >= 0; j--) {
            table[i * cols + j] = x[i] === y[j]
                ? table[(i + 1) * cols + j + 1] + 1
                : Math.max(table[(i + 1) * cols + j], table[i * cols + j + 1]);
        }
    }

    const parts = [];
    let i = 0;
    let j = 0;

    while (i < x.length && j < y.length) {
        if (x[i] === y[j]) {
            parts.push({ type: 'same', text: x[i] });
            i++;
            j++;
        } else if (table[(i + 1) * cols + j] >= table[i * cols + j + 1]) {
            parts.push({ type: 'removed', text: x[i++] });
        } else {
            parts.push({ type: 'added', text: y[j++] });
        }
    }

    while (i < x.length) parts.push({ type: 'removed', text: x[i++] });
    while (j < y.length) parts.push({ type: 'added', text: y[j++] });

    return parts;
}

// A run of changes becomes one removal and one addition. A space between
// two changes joins them (a single mark, too), so "a b" → "c d" reads as one change, not two.
function merge(parts) {
    const result = [];
    let removed = '';
    let added = '';

    const flush = () => {
        if (removed) result.push({ type: 'removed', text: removed });
        if (added) result.push({ type: 'added', text: added });
        removed = '';
        added = '';
    };

    parts.forEach((part, index) => {
        if (part.type === 'removed') {
            removed += part.text;
        } else if (part.type === 'added') {
            added += part.text;
        } else if ((removed || added) && /^(\s+|[^\s\p{L}\p{N}])$/u.test(part.text) && parts[index + 1] && parts[index + 1].type !== 'same') {
            removed += part.text;
            added += part.text;
        } else {
            flush();
            const last = result[result.length - 1];
            if (last?.type === 'same') last.text += part.text;
            else result.push({ ...part });
        }
    });

    flush();

    return result;
}

// What each side shows: the old text without its additions, and the new
// text without its removals.
export function sides(before, after) {
    const parts = diff(before, after);

    return {
        before: parts.filter((part) => part.type !== 'added'),
        after: parts.filter((part) => part.type !== 'removed'),
    };
}
