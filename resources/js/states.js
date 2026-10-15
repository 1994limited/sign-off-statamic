// How each state of a review round is shown, everywhere in the CP.
export function badge(state) {
    return {
        in_review: { text: __('In review'), color: 'blue' },
        rejected: { text: __('Sent back'), color: 'red' },
        withdrawn: { text: __('Withdrawn'), color: 'default' },
        outdated: { text: __('Needs resubmitting'), color: 'amber' },
        published: { text: __('Signed off'), color: 'green' },
        skipped: { text: __('Skipped'), color: 'amber' },
        none: { text: __('Not in review'), color: 'default' },
    }[state] ?? { text: state, color: 'default' };
}
