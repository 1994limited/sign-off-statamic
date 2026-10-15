<!--
    The dashboard's Sign-off widget: what's waiting for you, what was sent
    back to you, and your entries in review.
-->
<script>
import { Widget } from '@statamic/cms/ui';
import icon from '../../svg/sign-off.svg?raw';

export default {
    components: { Widget },

    props: {
        title: { type: String, required: true },
        waiting: { type: Array, required: true },
        waitingTotal: { type: Number, required: true },
        sentBack: { type: Array, required: true },
        yours: { type: Array, required: true },
        url: { type: String, required: true },
    },

    computed: {
        icon: () => icon,

        quiet() {
            return !this.waiting.length && !this.sentBack.length && !this.yours.length;
        },
    },
};
</script>

<template>
    <Widget :title="title" :icon="icon" :href="url">
        <div class="space-y-4 px-4 py-3 text-sm">
            <p v-if="quiet" class="text-gray-500">{{ __('Nothing needs you. Entries waiting for your review will show here.') }}</p>

            <section v-if="waiting.length" :aria-label="__('Waiting for you')">
                <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Waiting for you') }}</h3>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800!">
                    <li v-for="row in waiting" :key="row.id" class="flex items-baseline justify-between gap-3 py-1.5">
                        <span class="min-w-0 truncate">
                            <a :href="row.entry.edit_url" class="font-medium hover:underline!">{{ row.entry.title }}</a>
                            <span class="text-gray-500"> · {{ row.step }}</span>
                        </span>
                        <time v-if="row.waiting_since" class="shrink-0 text-xs text-gray-500" :datetime="row.waiting_since.iso" :title="row.waiting_since.full">{{ row.waiting_since.human }}</time>
                    </li>
                </ul>
                <a v-if="waitingTotal > waiting.length" :href="url" class="mt-1 inline-block text-xs text-blue-700 hover:underline! dark:text-blue-400!">
                    {{ __('All :count waiting', { count: waitingTotal }) }} →
                </a>
            </section>

            <section v-if="sentBack.length" :aria-label="__('Sent back to you')">
                <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-red-700 dark:text-red-400!">{{ __('Sent back to you') }}</h3>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800!">
                    <li v-for="row in sentBack" :key="row.id" class="py-1.5">
                        <a :href="row.entry.edit_url" class="font-medium hover:underline!">{{ row.entry.title }}</a>
                        <span v-if="row.last?.by" class="text-gray-500"> · {{ row.last.by }}</span>
                        <span v-if="row.last?.comment" class="block truncate text-xs italic text-gray-600 dark:text-gray-400" :title="row.last.comment">“{{ row.last.comment }}”</span>
                    </li>
                </ul>
            </section>

            <section v-if="yours.length" :aria-label="__('Yours in review')">
                <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Yours in review') }}</h3>
                <ul class="divide-y divide-gray-100 dark:divide-gray-800!">
                    <li v-for="row in yours" :key="row.id" class="flex items-baseline justify-between gap-3 py-1.5">
                        <a :href="row.entry.edit_url" class="min-w-0 truncate hover:underline!">{{ row.entry.title }}</a>
                        <span class="shrink-0 text-xs text-gray-500">{{ __('Waiting for :step', { step: row.step }) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </Widget>
</template>
