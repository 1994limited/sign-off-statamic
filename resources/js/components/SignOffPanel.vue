<!--
    The Sign-off panel in an entry's sidebar: where the entry is in its
    review, who's next, why it was sent back, and what happened before.
    Everything comes from the server (Panel.php) as meta; nothing here is
    saved. The actions themselves are in the entry's ••• menu.
-->
<script>
import { FieldtypeMixin } from '@statamic/cms';
import { Badge, Icon } from '@statamic/cms/ui';
import { badge } from '../states.js';

export default {
    mixins: [FieldtypeMixin],

    components: { Badge, Icon },

    data() {
        return { showHistory: false };
    },

    computed: {
        panel() {
            return this.meta?.panel ?? null;
        },

        badge() {
            return badge(this.panel?.state ?? 'none');
        },

        // Past rounds: the open one is already shown above.
        pastRounds() {
            return (this.panel?.history ?? []).filter((round) => round.state !== 'in_review');
        },
    },
};
</script>

<template>
    <div v-if="panel" class="sign-off-panel space-y-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <Badge :text="badge.text" :color="badge.color" size="sm" />
            <span class="font-medium">{{ panel.headline }}</span>
        </div>

        <div v-if="panel.problems?.length" class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-800! dark:bg-amber-950/40!">
            <p class="font-medium text-amber-900 dark:text-amber-100!">{{ __('Setup needs attention') }}</p>
            <ul class="mt-1 list-disc space-y-1 pl-4 text-amber-900 dark:text-amber-100!">
                <li v-for="(problem, index) in panel.problems" :key="index">{{ problem }}</li>
            </ul>
        </div>

        <div v-if="panel.rejection" class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900! dark:bg-red-950/40!">
            <p class="font-medium text-red-800 dark:text-red-200!">
                {{ __('Sent back by :name', { name: panel.rejection.by }) }}
                <span v-if="panel.rejection.step" class="font-normal">({{ panel.rejection.step }})</span>
            </p>
            <p v-if="panel.rejection.reason" class="mt-1 whitespace-pre-line text-red-900 dark:text-red-100!">{{ panel.rejection.reason }}</p>
            <p class="mt-2 text-xs text-red-700 dark:text-red-300!">
                <time v-if="panel.rejection.at" :datetime="panel.rejection.at.iso" :title="panel.rejection.at.full">{{ panel.rejection.at.human }}</time>
                · {{ __('Make the changes, save, then submit again.') }}
            </p>
        </div>

        <ol v-if="panel.steps.length" class="space-y-2" :aria-label="__('Steps')">
            <li v-for="step in panel.steps" :key="step.handle" class="flex gap-2">
                <span
                    class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full"
                    :class="{
                        'bg-green-600 text-white': step.status === 'done',
                        'border-2 border-blue-600 dark:border-blue-400!': step.status === 'current',
                        'border border-gray-300 dark:border-gray-600!': step.status === 'waiting',
                    }"
                >
                    <svg v-if="step.status === 'done'" viewBox="0 0 12 12" fill="none" class="size-2.5" aria-hidden="true">
                        <path d="M2.5 6.5 5 9l4.5-6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="sr-only">{{ { done: __('Done'), current: __('Current step'), waiting: __('To come') }[step.status] }}</span>
                </span>
                <div class="min-w-0">
                    <p :class="step.status === 'current' ? 'font-medium' : ''">{{ step.title }}</p>
                    <p v-if="step.status === 'done'" class="text-xs text-gray-500">
                        {{ __('Approved by :name', { name: step.by }) }}
                        <template v-if="step.at">· <time :datetime="step.at.iso" :title="step.at.full">{{ step.at.human }}</time></template>
                    </p>
                    <p v-else-if="step.approvers" class="text-xs text-gray-500">{{ step.approvers }}</p>
                    <p v-if="step.comment" class="mt-1 whitespace-pre-line text-xs italic">“{{ step.comment }}”</p>
                </div>
            </li>
        </ol>

        <div v-if="panel.submitted" class="border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-gray-700!">
            {{ __('Submitted by :name', { name: panel.submitted.by }) }}
            · <time :datetime="panel.submitted.at?.iso" :title="panel.submitted.at?.full">{{ panel.submitted.at?.human }}</time>
            <p v-if="panel.submitted.note" class="mt-1 whitespace-pre-line text-sm text-gray-800 dark:text-gray-200!">“{{ panel.submitted.note }}”</p>
        </div>

        <p v-if="panel.next" class="rounded-lg bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800!">{{ panel.next }}</p>

        <div v-if="pastRounds.length" class="border-t border-gray-200 pt-3 dark:border-gray-700!">
            <button type="button" class="flex items-center gap-1 text-xs text-gray-600 hover:text-gray-900! dark:text-gray-400 dark:hover:text-gray-100!" :aria-expanded="showHistory" @click="showHistory = !showHistory">
                <Icon name="chevron-right" class="size-3 transition-transform" :class="{ 'rotate-90': showHistory }" />
                {{ __('Earlier reviews (:count)', { count: pastRounds.length + panel.more_history }) }}
            </button>

            <ol v-if="showHistory" class="mt-2 space-y-3">
                <li v-for="round in pastRounds" :key="round.id" class="space-y-1">
                    <p v-for="(event, index) in round.events" :key="index" class="text-xs">
                        <span class="font-medium">{{ event.label }}</span>
                        <template v-if="event.step"> ({{ event.step }})</template>
                        <template v-if="event.by"> · {{ event.by }}</template>
                        <template v-if="event.at">· <time :datetime="event.at.iso" :title="event.at.full">{{ event.at.human }}</time></template>
                        <span v-if="event.comment" class="block whitespace-pre-line italic text-gray-600 dark:text-gray-400">“{{ event.comment }}”</span>
                    </p>
                </li>
            </ol>
        </div>
    </div>
</template>
