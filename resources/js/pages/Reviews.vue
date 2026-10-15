<!--
    Content → Sign-off: what's waiting for you (longest wait first),
    everything in review, and finished rounds. Rows link to the entry,
    where the review itself happens.
-->
<script>
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Header, Panel, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import { badge } from '../states.js';
import icon from '../../svg/sign-off.svg?raw';

export default {
    components: { Alert, Badge, Button, Head, Header, Link, Panel, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows },

    props: {
        tab: { type: String, required: true },
        counts: { type: Object, required: true },
        rows: { type: Array, required: true },
        pagination: { type: Object, required: true },
        collection: { type: String, default: null },
        collections: { type: Array, default: () => [] },
        problems: { type: Array, default: () => [] },
        urls: { type: Object, required: true },
    },

    computed: {
        icon: () => icon,

        tabs() {
            return [
                { handle: 'waiting', label: __('Waiting for you'), count: this.counts.waiting },
                { handle: 'in_review', label: __('In review'), count: this.counts.in_review },
                { handle: 'history', label: __('History'), count: null },
            ];
        },

        collectionOptions() {
            return [{ value: '', label: __('All collections') }, ...this.collections];
        },

        empty() {
            return {
                waiting: __('Nothing is waiting for you.'),
                in_review: __('Nothing is in review.'),
                history: __('Nothing has been through sign-off yet.'),
            }[this.tab];
        },
    },

    methods: {
        badge,

        url(changes = {}) {
            const params = { tab: this.tab, collection: this.collection, page: 1, ...changes };
            const query = new URLSearchParams();
            if (params.tab) query.set('tab', params.tab);
            if (params.collection) query.set('collection', params.collection);
            if (params.page > 1) query.set('page', params.page);
            const string = query.toString();
            return this.urls.index + (string ? `?${string}` : '');
        },

        filter(collection) {
            router.get(this.url({ collection: collection || null }), {}, { preserveState: true });
        },
    },
};
</script>

<template>
    <Head :title="__('Sign-off')" />

    <div class="mx-auto max-w-5xl">
        <Header :title="__('Sign-off')" :icon="icon" />

        <Alert
            v-if="problems.length"
            class="mb-6"
            :variant="problems.some((problem) => problem.blocking) ? 'warning' : 'default'"
        >
            <p class="font-medium">{{ __('Sign-off’s setup needs attention') }}</p>
            <ul class="mt-1 list-disc space-y-1 pl-4">
                <li v-for="(problem, index) in problems" :key="index">
                    <strong v-if="problem.collection">{{ problem.collection }}:</strong>
                    {{ problem.message }}
                </li>
            </ul>
            <p class="mt-2 text-xs">{{ __('Only people who can configure collections see this. Run php please sign-off:check to see it from the command line.') }}</p>
        </Alert>

        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700!" :aria-label="__('Sign-off lists')">
                <Link
                    v-for="item in tabs"
                    :key="item.handle"
                    :href="url({ tab: item.handle })"
                    :aria-current="item.handle === tab ? 'page' : null"
                    class="-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm"
                    :class="item.handle === tab
                        ? 'border-blue-600 font-medium text-gray-900 dark:border-blue-400! dark:text-white!'
                        : 'border-transparent text-gray-600 hover:text-gray-900! dark:text-gray-400 dark:hover:text-white!'"
                >
                    {{ item.label }}
                    <span
                        v-if="item.count !== null"
                        class="rounded-full px-1.5 text-xs tabular-nums"
                        :class="item.handle === 'waiting' && item.count ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600 dark:bg-gray-800! dark:text-gray-300!'"
                    >{{ item.count }}</span>
                </Link>
            </nav>

            <div v-if="collections.length" class="w-48">
                <Select
                    :model-value="collection ?? ''"
                    :options="collectionOptions"
                    :aria-label="__('Collection')"
                    @update:model-value="filter"
                />
            </div>
        </div>

        <Panel>
            <Table v-if="rows.length">
                <TableColumns>
                    <TableColumn>{{ __('Entry') }}</TableColumn>
                    <template v-if="tab === 'history'">
                        <TableColumn>{{ __('Outcome') }}</TableColumn>
                        <TableColumn class="text-right">{{ __('When') }}</TableColumn>
                    </template>
                    <template v-else>
                        <TableColumn>{{ __('Waiting for') }}</TableColumn>
                        <TableColumn>{{ __('Submitted by') }}</TableColumn>
                        <TableColumn class="text-right">{{ tab === 'waiting' ? __('Waiting') : __('Submitted') }}</TableColumn>
                        <TableColumn v-if="tab === 'waiting'"><span class="sr-only">{{ __('Review') }}</span></TableColumn>
                    </template>
                </TableColumns>

                <TableRows>
                    <TableRow v-for="row in rows" :key="row.id">
                        <TableCell>
                            <a v-if="row.entry.edit_url" :href="row.entry.edit_url" class="font-medium hover:underline!">{{ row.entry.title }}</a>
                            <span v-else class="text-gray-500">{{ row.entry.title }}</span>
                            <span class="block text-xs text-gray-500">
                                {{ row.collection }}<template v-if="row.site"> · {{ row.site }}</template>
                            </span>
                        </TableCell>

                        <template v-if="tab === 'history'">
                            <TableCell>
                                <Badge :text="badge(row.state).text" :color="badge(row.state).color" size="sm" />
                                <span v-if="row.last" class="ml-2 text-sm">
                                    <template v-if="row.last.by">{{ row.last.by }}<template v-if="row.last.step"> ({{ row.last.step }})</template></template>
                                    <span v-else class="text-gray-500">{{ row.last.label }}</span>
                                </span>
                                <span v-if="row.last?.comment && row.state !== 'published'" class="mt-1 block max-w-md truncate text-xs italic text-gray-600 dark:text-gray-400" :title="row.last.comment">“{{ row.last.comment }}”</span>
                            </TableCell>
                            <TableCell class="text-right text-gray-500">
                                <time v-if="row.last?.at" :datetime="row.last.at.iso" :title="row.last.at.full">{{ row.last.at.human }}</time>
                            </TableCell>
                        </template>

                        <template v-else>
                            <TableCell>
                                {{ row.step }}
                                <span class="block text-xs text-gray-500">{{ row.progress }}</span>
                            </TableCell>
                            <TableCell>
                                {{ row.submitted_by }}
                                <span v-if="row.note" class="block max-w-xs truncate text-xs italic text-gray-500" :title="row.note">“{{ row.note }}”</span>
                            </TableCell>
                            <TableCell class="text-right text-gray-500">
                                <time
                                    v-if="tab === 'waiting' ? row.waiting_since : row.submitted_at"
                                    :datetime="(tab === 'waiting' ? row.waiting_since : row.submitted_at).iso"
                                    :title="(tab === 'waiting' ? row.waiting_since : row.submitted_at).full"
                                >{{ (tab === 'waiting' ? row.waiting_since : row.submitted_at).human }}</time>
                            </TableCell>
                            <TableCell v-if="tab === 'waiting'" class="text-right">
                                <Button v-if="row.entry.edit_url" :href="row.entry.edit_url" size="sm" :text="__('Review')" />
                            </TableCell>
                        </template>
                    </TableRow>
                </TableRows>
            </Table>

            <p v-else class="p-6 text-center text-sm text-gray-500">{{ empty }}</p>
        </Panel>

        <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm" :aria-label="__('Pages')">
            <Link v-if="pagination.page > 1" :href="url({ page: pagination.page - 1 })" class="hover:underline!">← {{ __('Previous') }}</Link>
            <span v-else />
            <span class="text-gray-500">{{ __(':page of :last', { page: pagination.page, last: pagination.last_page }) }}</span>
            <Link v-if="pagination.page < pagination.last_page" :href="url({ page: pagination.page + 1 })" class="hover:underline!">{{ __('Next') }} →</Link>
            <span v-else />
        </nav>
    </div>
</template>
