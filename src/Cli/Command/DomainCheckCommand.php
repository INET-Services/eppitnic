<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Epp\Domain;

/**
 * Availability of one or more domains at the registry.
 */
final class DomainCheckCommand extends Command
{
    public function describe(): string {
        return 'check whether domains are available for registration';
    }

    public function arguments(): string {
        return '<domain>...';
    }

    public function options(): array {
        return self::fileOption('domain names');
    }

    public function run(): int {
        $names = $this->domainNames();
        if ($names === []) {
            throw new UsageError('give at least one domain name, or --file=PATH');
        }

        $results = $this->withSession(function ($nic) use ($names) {
            $domain = new Domain($nic);
            $out = [];

            // the registry caps a single <check> at five names, which is why
            // Domain::check() slices; batching here keeps that invisible to
            // the caller, who may pass a file of any length
            foreach (array_chunk($names, 5) as $batch) {
                $answer = $domain->check($batch);

                if ( ! $answer->answered()) {
                    // the whole batch went unanswered, so every name in it is
                    // unknown -- not "taken", which is what reading a failure
                    // as a boolean would have made of it
                    foreach ($batch as $name) {
                        $out[$name] = ['available' => null, 'reason' => $answer->error() ?: 'check failed'];
                    }
                    continue;
                }
                foreach ($answer->all() as $name => $result) {
                    $out[$name] = $result;
                }
            }
            return $out;
        });

        $failed = false;
        foreach ($results as $name => $result) {
            if ($result['available'] === null) {
                $failed = true;
                $this->warn("{$name}: check failed ({$result['reason']})");
                continue;
            }
            $this->record(
                sprintf('%-40s %s%s', $name, $result['available'] ? 'available' : 'taken',
                    $result['available'] ? '' : ' (' . $result['reason'] . ')'),
                ['domain' => $name] + $result
            );
        }

        return $failed ? DOMAIN_CHECK_FAILED : 0;
    }
}
