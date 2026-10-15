<!--
    The comparison's lightbox: an image (or file) at full size, next to
    what it replaced. ← and → step through the changed images; Escape
    closes it, back to the thumbnail it was opened from.
-->
<script>
import { Button, Modal } from '@statamic/cms/ui';

export default {
    components: { Button, Modal },

    props: {
        slides: { type: Array, required: true },
        index: { type: Number, default: null },
        beforeLabel: { type: String, required: true },
        afterLabel: { type: String, required: true },
    },

    emits: ['update:index'],

    computed: {
        open: {
            get() {
                return this.index !== null;
            },
            set(open) {
                if (!open) this.$emit('update:index', null);
            },
        },

        slide() {
            return this.index === null ? null : this.slides[this.index];
        },

        sides() {
            if (!this.slide) return [];

            return [
                { key: 'before', label: this.beforeLabel, file: this.slide.before, status: this.slide.before?.status === 'removed' ? __('Removed') : null },
                { key: 'after', label: this.afterLabel, file: this.slide.after, status: this.slide.after?.status === 'added' ? __('Added') : null },
            ].filter((side) => side.file || this.slide.pair);
        },

        title() {
            if (!this.slide) return '';
            return this.slides.length > 1
                ? __(':field (:n of :total)', { field: this.slide.field, n: this.index + 1, total: this.slides.length })
                : this.slide.field;
        },
    },

    watch: {
        open(open) {
            // While it's open, ← and → work wherever focus is in it.
            document[open ? 'addEventListener' : 'removeEventListener']('keydown', this.keydown);
        },
    },

    beforeUnmount() {
        document.removeEventListener('keydown', this.keydown);
    },

    methods: {
        step(by) {
            if (this.slides.length < 2) return;
            this.$emit('update:index', (this.index + by + this.slides.length) % this.slides.length);
        },

        keydown(event) {
            if (event.target.closest?.('input, textarea, select, [contenteditable]')) return;

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                this.step(1);
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                this.step(-1);
            }
        },
    },
};
</script>

<template>
    <Modal v-model:open="open" :title="title" class="sign-off-lightbox">
        <div v-if="slide">
            <div class="grid gap-4" :class="sides.length > 1 ? 'md:grid-cols-2' : ''">
                <figure v-for="side in sides" :key="side.key" class="m-0 min-w-0">
                    <figcaption class="mb-2 flex items-center gap-2 text-xs uppercase tracking-wide text-gray-500">
                        {{ side.label }}
                        <span
                            v-if="side.status"
                            class="rounded px-1.5 py-0.5 text-[11px] font-medium normal-case tracking-normal text-white"
                            :class="side.key === 'before' ? 'bg-red-600' : 'bg-green-700'"
                        >{{ side.status }}</span>
                    </figcaption>

                    <div class="flex min-h-48 items-center justify-center overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800!">
                        <img v-if="side.file?.full" :src="side.file.full" :alt="side.file.alt || side.file.name" class="max-h-[65vh] w-full object-contain" />
                        <div v-else-if="side.file" class="p-6 text-center text-sm text-gray-600 dark:text-gray-300">
                            <p>{{ side.file.missing ? __('No longer in the asset library') : side.file.name }}</p>
                            <a v-if="side.file.url" :href="side.file.url" target="_blank" rel="noopener" class="mt-2 inline-block underline">{{ __('Open the file') }}</a>
                        </div>
                        <p v-else class="p-6 text-sm italic text-gray-500">{{ __('Nothing here') }}</p>
                    </div>

                    <p v-if="side.file" class="mt-2 truncate text-sm" :title="side.file.name">{{ side.file.name }}</p>
                    <p v-if="side.file?.alt" class="text-sm italic text-gray-600 dark:text-gray-400">{{ __('Alt text') }}: “{{ side.file.alt }}”</p>
                </figure>
            </div>
        </div>

        <template v-if="slides.length > 1" #footer>
            <div class="flex w-full items-center justify-between gap-2">
                <Button size="sm" icon="chevron-left" :text="__('Previous')" @click="step(-1)" />
                <span class="text-xs text-gray-500">{{ __('Use ← and → to move between images') }}</span>
                <Button size="sm" icon-append="chevron-right" :text="__('Next')" @click="step(1)" />
            </div>
        </template>
    </Modal>
</template>
