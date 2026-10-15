<!--
    "Compare changes" in the Sign-off panel: a slide-over with each field's
    old and new text side by side, the words that differ highlighted.
    Compared with the live version, or with what was submitted last time.
-->
<script>
import { Button, Stack, Switch } from '@statamic/cms/ui';
import { sides } from '../diff.js';
import Lightbox from './Lightbox.vue';
import MediaList from './MediaList.vue';

export default {
    components: { Button, Lightbox, MediaList, Stack, Switch },

    props: {
        url: { type: String, required: true },
    },

    data() {
        return { open: false, loading: false, error: null, data: null, against: null, showUnchanged: false, slide: null, extraSlide: null };
    },

    computed: {
        rows() {
            return (this.data?.fields ?? [])
                .filter((field) => field.changed || this.showUnchanged)
                .map((field) => ({ ...field, sides: field.changed ? sides(field.before, field.after) : null }));
        },

        // The changed images, in order, for the lightbox: a replaced file
        // next to its replacement, and added or removed files on their own.
        changedSlides() {
            return (this.data?.fields ?? []).filter((field) => field.changed && field.media).flatMap((field) => {
                const { before, after, single } = field.media;

                if (single) {
                    return [{ field: field.display, before: before[0] ?? null, after: after[0] ?? null, pair: true }];
                }

                return [
                    ...before.filter((file) => file.status === 'removed').map((file) => ({ field: field.display, before: file, after: null })),
                    ...after.filter((file) => file.status === 'added').map((file) => ({ field: field.display, before: null, after: file })),
                ];
            });
        },

        slides() {
            return this.extraSlide ? [...this.changedSlides, this.extraSlide] : this.changedSlides;
        },
    },

    methods: {
        show() {
            this.open = true;
            this.load(this.against);
        },

        openFile(row, file, side) {
            const index = this.changedSlides.findIndex((slide) => slide[side]?.key === file.key && slide.field === row.display);

            if (index !== -1) {
                this.extraSlide = null;
                this.slide = index;
                return;
            }

            // An unchanged file: shown on its own.
            this.extraSlide = { field: row.display, [side]: { ...file, status: 'same' }, [side === 'before' ? 'after' : 'before']: null };
            this.slide = this.changedSlides.length;
        },

        closeLightbox(index) {
            this.slide = index;
            if (index === null) this.extraSlide = null;
        },

        async load(against) {
            this.loading = true;
            this.error = null;

            try {
                const response = await this.$axios.get(this.url, { params: against ? { against } : {} });
                this.data = response.data;
                this.against = response.data.against;
            } catch (e) {
                this.error = __('The comparison couldn’t be loaded. Try again.');
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>

<template>
    <Button size="sm" icon="eye" :text="__('Compare changes')" class="w-full" @click="show" />

    <Stack v-model:open="open" :title="__('Compare changes')" size="full">
        <div class="sign-off-panel mx-auto max-w-6xl p-4 text-sm">
            <div v-if="data" class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div v-if="Object.keys(data.options).length > 1" class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800!" role="group" :aria-label="__('Compare with')">
                    <button
                        v-for="(label, handle) in data.options"
                        :key="handle"
                        type="button"
                        class="rounded-md px-3 py-1"
                        :class="handle === against ? 'bg-white font-medium shadow-sm dark:bg-gray-950!' : 'text-gray-600 hover:text-gray-900! dark:text-gray-400 dark:hover:text-white!'"
                        :aria-pressed="handle === against"
                        @click="load(handle)"
                    >{{ label }}</button>
                </div>
                <p v-else class="text-gray-600 dark:text-gray-400">{{ __('Compared with') }}: {{ Object.values(data.options)[0] }}</p>

                <label class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                    <Switch v-model="showUnchanged" size="sm" />
                    {{ __('Show unchanged fields') }}
                </label>
            </div>

            <p v-if="error" class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950/40! dark:text-red-200!">{{ error }}</p>
            <p v-else-if="loading && !data" class="p-6 text-center text-gray-500">{{ __('Loading…') }}</p>

            <template v-else-if="data">
                <p v-if="!data.changed" class="rounded-lg bg-gray-100 p-6 text-center text-gray-600 dark:bg-gray-800! dark:text-gray-300!">
                    {{ __('Nothing has changed.') }}
                </p>
                <p v-else class="mb-3 text-gray-600 dark:text-gray-400">
                    {{ data.changed === 1 ? __('1 field changed') : __(':count fields changed', { count: data.changed }) }}
                </p>

                <div class="space-y-4" :class="{ 'opacity-60': loading }">
                    <section v-for="row in rows" :key="row.handle" class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700!">
                        <h3 class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2 font-medium dark:border-gray-700! dark:bg-gray-900!">
                            {{ row.display }}
                            <span v-if="!row.changed" class="text-xs font-normal text-gray-500">{{ __('Unchanged') }}</span>
                        </h3>

                        <div class="grid md:grid-cols-2 md:divide-x divide-gray-200 dark:divide-gray-700!">
                            <div class="p-3">
                                <p class="mb-1 text-xs uppercase tracking-wide text-gray-500">{{ data.before_label }}</p>
                                <MediaList v-if="row.media?.kind === 'assets' && row.before !== null" :files="row.media.before" side="before" @open="(file) => openFile(row, file, 'before')" />
                                <div v-else-if="row.changed && row.before !== null" class="whitespace-pre-wrap break-words">
                                    <template v-for="(part, index) in row.sides.before" :key="index">
                                        <del v-if="part.type === 'removed'" class="rounded-sm bg-red-100 text-red-900 no-underline dark:bg-red-950! dark:text-red-200!">{{ part.text }}</del>
                                        <span v-else>{{ part.text }}</span>
                                    </template>
                                </div>
                                <div v-else-if="row.before !== null" class="whitespace-pre-wrap break-words">{{ row.before }}</div>
                                <p v-else class="italic text-gray-500">{{ __('Empty') }}</p>
                                <MediaList v-if="row.media?.kind === 'bard' && row.media.before.length" class="mt-3" :files="row.media.before" side="before" @open="(file) => openFile(row, file, 'before')" />
                            </div>

                            <div class="border-t border-gray-200 p-3 md:border-t-0 dark:border-gray-700!">
                                <p class="mb-1 text-xs uppercase tracking-wide text-gray-500">{{ data.after_label }}</p>
                                <MediaList v-if="row.media?.kind === 'assets'" :files="row.media.after" side="after" @open="(file) => openFile(row, file, 'after')" />
                                <div v-else-if="row.changed && row.after !== null" class="whitespace-pre-wrap break-words">
                                    <template v-for="(part, index) in row.sides.after" :key="index">
                                        <ins v-if="part.type === 'added'" class="rounded-sm bg-green-100 text-green-900 no-underline dark:bg-green-950! dark:text-green-200!">{{ part.text }}</ins>
                                        <span v-else>{{ part.text }}</span>
                                    </template>
                                </div>
                                <div v-else-if="row.after !== null" class="whitespace-pre-wrap break-words">{{ row.after }}</div>
                                <p v-else class="italic text-gray-500">{{ row.changed ? __('Removed') : __('Empty') }}</p>
                                <MediaList v-if="row.media?.kind === 'bard' && row.media.after.length" class="mt-3" :files="row.media.after" side="after" @open="(file) => openFile(row, file, 'after')" />
                            </div>
                        </div>
                    </section>
                </div>
            </template>
        </div>
    </Stack>

    <Lightbox
        v-if="data"
        :slides="slides"
        :index="slide"
        :before-label="data.before_label"
        :after-label="data.after_label"
        @update:index="closeLightbox"
    />
</template>
