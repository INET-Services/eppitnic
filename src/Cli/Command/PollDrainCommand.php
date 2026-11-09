<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Session;

/**
 * Read the registry's message queue into the local database. Each message is
 * polled, stored, then acknowledged -- the acknowledgement reveals the next --
 * so this empties the queue and cannot be repeated for the same messages.
 */
final class PollDrainCommand extends Command
{
    public function describe(): string {
        return 'read and acknowledge the registry message queue';
    }

    public function options(): array {
        return [
            'limit=' => 'stop after this many messages (default: drain the queue)',
            'peek'   => 'read the first message without acknowledging it',
        ] + self::MUTATING_OPTIONS;
    }

    public function run(): int {
        if ($this->isDryRun()) {
            throw new UsageError(
                '--dry-run does not apply to poll: what it would send depends on what the'
                . ' queue holds, which only the registry can say'
            );
        }

        $limit = (int) $this->option('limit', 0);
        $peek = $this->hasOption('peek');

        if ( ! $peek && ! $this->confirm('Drain the registry message queue? Acknowledged messages cannot be re-read.')) {
            $this->line('nothing done');
            return 0;
        }

        $drained = 0;

        $this->withSession(function ($nic, $session) use ($limit, $peek, &$drained) {
            /** @var Session $session */
            $waiting = $session->pollMessageCount();

            if ($waiting === 0) {
                $this->line('the queue is empty');
                return;
            }
            $this->line("{$waiting} message(s) waiting");

            while ($session->pollMessageCount() > 0) {
                $id = $session->pollID();

                // poll 'req' stores the message (the registry refuses an id on
                // it); 'ack' removes it from the queue and uncovers the next
                if ( ! $session->poll(true, 'req')) {
                    $this->failures++;
                    $this->warn("message {$id}: " . $session->getError());
                    return;
                }

                $this->record(
                    sprintf('%-12s %s', $id, $session->get('msgTitle')),
                    ['id' => $id, 'title' => $session->get('msgTitle')]
                );
                $drained++;

                if ($peek) {
                    $this->line('--peek: left in the queue');
                    return;
                }

                if ( ! $session->poll(false, 'ack', $id)) {
                    $this->failures++;
                    $this->warn("message {$id}: not acknowledged (" . $session->getError() . ')');
                    return;
                }

                if ($limit > 0 && $drained >= $limit) {
                    return;
                }
            }
        });

        $this->line('');
        $this->line("{$drained} message(s) read");

        return $this->outcome(POLL_FAILED);
    }
}
