<?php

return [
    // Actions
    'action_submit' => 'Submit for sign-off',
    'action_approve' => 'Approve',
    'action_approve_publish' => 'Approve and publish',
    'action_reject' => 'Send back',
    'action_withdraw' => 'Withdraw from review',
    'confirm_submit' => 'Send this entry for sign-off? It can\'t be changed while it\'s in review.',
    'confirm_approve' => 'Approve this step?',
    'confirm_reject' => 'Send this entry back to its author?',
    'confirm_withdraw' => 'Take this entry out of review? Approvals so far will be lost.',
    'dirty_warning' => 'Save your changes first: only saved changes are sent for sign-off.',

    // Results
    'submitted' => 'Sent for sign-off. Waiting for :step.',
    'approved' => 'Approved. Waiting for :step.',
    'signed_off' => 'Signed off and published.',
    'rejected' => 'Sent back to the author.',
    'withdrawn' => 'Taken out of review. You can edit it again.',
    'revision_message' => 'Signed off: :steps',

    // Refusals
    'not_covered' => 'This collection doesn\'t need sign-off.',
    'already_in_review' => 'This entry is already in review.',
    'nothing_to_review' => 'There are no changes to review: edit and save the entry first.',
    'cannot_edit' => 'You can\'t edit this entry, so you can\'t submit it.',
    'not_in_review' => 'This entry isn\'t in review.',
    'not_your_step' => 'This entry is waiting for :step, and you can\'t approve that step.',
    'own_submission' => 'You submitted this entry, so someone else needs to review it.',
    'not_your_submission' => 'Only the person who submitted this entry can withdraw it.',
    'reason_required' => 'Say what needs to change.',
    'changed_since' => 'This entry changed after it was submitted, so it needs to be submitted again.',
    'publish_failed' => 'The entry could not be published.',
];
