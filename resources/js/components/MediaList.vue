<!--
    One side of a field's images and files in the comparison: thumbnails,
    marked when they were added or removed. Each opens the lightbox.
-->
<script>
export default {
    props: {
        files: { type: Array, required: true },
        // Whether this is the old side ("removed" applies) or the new one.
        side: { type: String, required: true },
    },

    emits: ['open'],

    methods: {
        marked(file) {
            return (this.side === 'before' && file.status === 'removed') || (this.side === 'after' && file.status === 'added');
        },

        label(file) {
            if (file.missing) return __('No longer in the asset library');
            if (file.linked) return __('Linked image (not loaded)');
            if (file.elsewhere) return __('Not previewed: from another asset folder');
            return __('File');
        },
    },
};
</script>

<template>
    <ul v-if="files.length" class="flex flex-wrap gap-3">
        <li v-for="file in files" :key="file.key" class="w-36">
            <button
                type="button"
                class="group block w-full rounded-lg p-1 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                :class="marked(file)
                    ? (side === 'before' ? 'ring-2 ring-red-500 dark:ring-red-400!' : 'ring-2 ring-green-600 dark:ring-green-400!')
                    : 'ring-1 ring-gray-200 dark:ring-gray-700!'"
                :aria-label="__('Open :name', { name: file.name }) + (marked(file) ? ` (${side === 'before' ? __('Removed') : __('Added')})` : '')"
                @click="$emit('open', file, side)"
            >
                <span class="relative flex aspect-square items-center justify-center overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800!">
                    <img v-if="file.thumbnail" :src="file.thumbnail" :alt="file.alt || ''" class="size-full object-contain" loading="lazy" />
                    <span v-else class="px-2 text-center text-xs text-gray-500">
                        <svg viewBox="0 0 24 24" class="mx-auto mb-1 size-8" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" /><path d="M14 3v5h5" /></svg>
                        {{ label(file) }}
                    </span>
                    <span
                        v-if="marked(file)"
                        class="absolute left-1 top-1 rounded px-1.5 py-0.5 text-[11px] font-medium text-white"
                        :class="side === 'before' ? 'bg-red-600' : 'bg-green-700'"
                    >{{ side === 'before' ? __('Removed') : __('Added') }}</span>
                </span>
                <span class="mt-1 block truncate text-xs" :title="file.name">{{ file.name }}</span>
                <span v-if="file.alt" class="block truncate text-xs italic text-gray-500" :title="file.alt">“{{ file.alt }}”</span>
            </button>
        </li>
    </ul>
    <p v-else class="italic text-gray-500">{{ __('None') }}</p>
</template>
