<?php

namespace NineteenNinetyFour\SignOff\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\SignOff\Rules;
use Statamic\Console\RunsInPlease;

/**
 * Check sign-off's setup: which collections it's running for, and what
 * stops it anywhere else. Fails when something is wrong, so it can run
 * in a deploy.
 */
class CheckSetup extends Command
{
    use RunsInPlease;

    protected $signature = 'sign-off:check';

    protected $description = 'Check that sign-off is set up and running for its collections';

    public function handle(Rules $rules): int
    {
        $configured = array_map('strval', array_keys((array) config('sign-off.collections', [])));
        $problems = collect($rules->problems());

        if ($configured === []) {
            $this->components->warn('No collections are set up for sign-off. Add them to config/sign-off.php.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Collection</>', '<fg=gray>Steps</>');

        foreach ($configured as $handle) {
            $running = $problems->contains(fn ($problem) => $problem['blocking'] && in_array($problem['collection'], [null, $handle], true)) === false;
            $steps = implode(' → ', array_map(fn ($step) => $step->title, $rules->steps($handle))) ?: '–';

            $this->components->twoColumnDetail(
                $handle.($running ? '' : ' <fg=yellow>(not running)</>'),
                $steps,
            );
        }

        $this->newLine();

        if ($problems->isEmpty()) {
            $this->components->info('Sign-off is set up and running.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $message = ($problem['collection'] ? "[{$problem['collection']}] " : '').$problem['message'];
            $problem['blocking'] ? $this->components->error($message) : $this->components->warn($message);
        }

        return self::FAILURE;
    }
}
