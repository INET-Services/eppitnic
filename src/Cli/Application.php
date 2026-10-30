<?php

namespace Eppitnic\Cli;

use Eppitnic\Cli\Command\ConfigAllowedOriginsCommand;
use Eppitnic\Cli\Command\ConfigDebugfileCommand;
use Eppitnic\Cli\Command\ConfigDnssecCommand;
use Eppitnic\Cli\Command\ConfigDomainReapSetCommand;
use Eppitnic\Cli\Command\ConfigDomainSyncCommand;
use Eppitnic\Cli\Command\ConfigDomainSyncSetCommand;
use Eppitnic\Cli\Command\ConfigEppPasswordCommand;
use Eppitnic\Cli\Command\ConfigEppServerCommand;
use Eppitnic\Cli\Command\ConfigEppSetCommand;
use Eppitnic\Cli\Command\ConfigKeepaliveCommand;
use Eppitnic\Cli\Command\ConfigMigrateCommand;
use Eppitnic\Cli\Command\ConfigPdnsApiCommand;
use Eppitnic\Cli\Command\ConfigPdnsNameserverCommand;
use Eppitnic\Cli\Command\ConfigPdnsSetCommand;
use Eppitnic\Cli\Command\ConfigPollProcessSetCommand;
use Eppitnic\Cli\Command\ConfigRegionSetCommand;
use Eppitnic\Cli\Command\ConfigRemoteAuthSetCommand;
use Eppitnic\Cli\Command\ConfigSafeNetworksCommand;
use Eppitnic\Cli\Command\ConfigSessionSerializeCommand;
use Eppitnic\Cli\Command\ConfigShowCommand;
use Eppitnic\Cli\Command\ConfigSmtpSetCommand;
use Eppitnic\Cli\Command\ConfigTrustedProxiesCommand;
use Eppitnic\Cli\Command\ContactCheckCommand;
use Eppitnic\Cli\Command\ContactCleanupCommand;
use Eppitnic\Cli\Command\ContactCreateCommand;
use Eppitnic\Cli\Command\ContactDeleteCommand;
use Eppitnic\Cli\Command\ContactFixEmailPrivacyCommand;
use Eppitnic\Cli\Command\ContactInfoCommand;
use Eppitnic\Cli\Command\ContactUpdateCommand;
use Eppitnic\Cli\Command\CronRunCommand;
use Eppitnic\Cli\Command\DoctorEppPasswordCommand;
use Eppitnic\Cli\Command\DoctorInactiveDomainsCommand;
use Eppitnic\Cli\Command\DoctorNormalizePayloadsCommand;
use Eppitnic\Cli\Command\DoctorOwnershipCommand;
use Eppitnic\Cli\Command\DoctorReparseMessagesCommand;
use Eppitnic\Cli\Command\DomainCheckCommand;
use Eppitnic\Cli\Command\DomainCreateCommand;
use Eppitnic\Cli\Command\DomainDeleteCommand;
use Eppitnic\Cli\Command\DomainExportCommand;
use Eppitnic\Cli\Command\DomainImportCommand;
use Eppitnic\Cli\Command\DomainInfoCommand;
use Eppitnic\Cli\Command\DomainReapDeletionsCommand;
use Eppitnic\Cli\Command\DomainRestoreCommand;
use Eppitnic\Cli\Command\DomainSetOwnerCommand;
use Eppitnic\Cli\Command\DomainSetRegistrantCommand;
use Eppitnic\Cli\Command\DomainStatusCommand;
use Eppitnic\Cli\Command\DomainSyncCommand;
use Eppitnic\Cli\Command\DomainTransferCommand;
use Eppitnic\Cli\Command\DomainUpdateCommand;
use Eppitnic\Cli\Command\PdnsSyncCommand;
use Eppitnic\Cli\Command\PollDrainCommand;
use Eppitnic\Cli\Command\PollListCommand;
use Eppitnic\Cli\Command\PollProcessCommand;
use Eppitnic\Cli\Command\ResellerCreateCommand;
use Eppitnic\Cli\Command\ResellerListCommand;
use Eppitnic\Cli\Command\ResellerSetCommand;
use Eppitnic\Cli\Command\SelftestReapCommand;
use Eppitnic\Cli\Command\SelftestRunCommand;
use Eppitnic\Cli\Command\SessionCreditCommand;
use Eppitnic\Cli\Command\SessionHelloCommand;
use Eppitnic\Cli\Command\SessionKeepaliveCommand;
use Eppitnic\Cli\Command\SetupCommand;
use Eppitnic\Cli\Command\UserActivateCommand;
use Eppitnic\Cli\Command\UserCreateCommand;
use Eppitnic\Cli\Command\UserDeactivateCommand;
use Eppitnic\Cli\Command\UserPasswordCommand;
use Eppitnic\Cli\Command\UserTokenCommand;

/**
 * Maps a verb to the class that implements it, and runs it. Separate from
 * bin/eppitnic so the dispatch is testable without spawning a process, leaving
 * that file only argv, exit codes and STDERR.
 */
final class Application
{
    /**
     * verb => command class. Two-word verbs are the norm, and the dispatcher
     * matches the longest first, so 'domain transfer approve' beats
     * 'domain transfer' when both are registered.
     *
     * @return array<string, class-string<Command>>
     */
    public static function commands(): array {
        return [
            'domain info'               => DomainInfoCommand::class,
            'domain check'              => DomainCheckCommand::class,
            'domain export'             => DomainExportCommand::class,
            'domain create'             => DomainCreateCommand::class,
            'domain import'             => DomainImportCommand::class,
            'domain transfer'           => DomainTransferCommand::class,
            'domain set-owner'          => DomainSetOwnerCommand::class,
            'domain set-registrant'     => DomainSetRegistrantCommand::class,
            'domain update'             => DomainUpdateCommand::class,
            'domain delete'             => DomainDeleteCommand::class,
            'domain restore'            => DomainRestoreCommand::class,
            'domain status'             => DomainStatusCommand::class,
            'domain sync'               => DomainSyncCommand::class,
            'domain reap-deletions'     => DomainReapDeletionsCommand::class,
            'contact info'              => ContactInfoCommand::class,
            'contact check'             => ContactCheckCommand::class,
            'contact create'            => ContactCreateCommand::class,
            'contact update'            => ContactUpdateCommand::class,
            'contact delete'            => ContactDeleteCommand::class,
            'contact cleanup'           => ContactCleanupCommand::class,
            'contact fix-email-privacy' => ContactFixEmailPrivacyCommand::class,
            'session hello'             => SessionHelloCommand::class,
            'session credit'            => SessionCreditCommand::class,
            'session keepalive'         => SessionKeepaliveCommand::class,
            'poll list'                 => PollListCommand::class,
            'poll drain'                => PollDrainCommand::class,
            'poll process'              => PollProcessCommand::class,
            'doctor ownership'          => DoctorOwnershipCommand::class,
            'doctor inactive-domains'   => DoctorInactiveDomainsCommand::class,
            'doctor reparse-messages'   => DoctorReparseMessagesCommand::class,
            'doctor epp-password'       => DoctorEppPasswordCommand::class,
            'doctor normalize-payloads' => DoctorNormalizePayloadsCommand::class,
            'pdns sync'                 => PdnsSyncCommand::class,
            'reseller list'             => ResellerListCommand::class,
            'reseller create'           => ResellerCreateCommand::class,
            'reseller set'              => ResellerSetCommand::class,
            'cron run'                  => CronRunCommand::class,
            'selftest run'              => SelftestRunCommand::class,
            'selftest reap'             => SelftestReapCommand::class,
            'setup'                     => SetupCommand::class,
            'user create'               => UserCreateCommand::class,
            'user token'                => UserTokenCommand::class,
            'user password'             => UserPasswordCommand::class,
            'user activate'             => UserActivateCommand::class,
            'user deactivate'           => UserDeactivateCommand::class,
            'config show'               => ConfigShowCommand::class,
            'config epp-server'         => ConfigEppServerCommand::class,
            'config epp-set'            => ConfigEppSetCommand::class,
            'config epp-password'       => ConfigEppPasswordCommand::class,
            'config keepalive'          => ConfigKeepaliveCommand::class,
            'config dnssec'             => ConfigDnssecCommand::class,
            'config domain-sync'        => ConfigDomainSyncCommand::class,
            'config domain-sync-set'    => ConfigDomainSyncSetCommand::class,
            'config domain-reap-set'    => ConfigDomainReapSetCommand::class,
            'config pdns-set'           => ConfigPdnsSetCommand::class,
            'config pdns-api'           => ConfigPdnsApiCommand::class,
            'config pdns-nameserver'    => ConfigPdnsNameserverCommand::class,
            'config poll-process-set'   => ConfigPollProcessSetCommand::class,
            'config smtp-set'           => ConfigSmtpSetCommand::class,
            'config region-set'         => ConfigRegionSetCommand::class,
            'config remote-auth-set'    => ConfigRemoteAuthSetCommand::class,
            'config session-serialize'  => ConfigSessionSerializeCommand::class,
            'config safe-networks'      => ConfigSafeNetworksCommand::class,
            'config trusted-proxies'    => ConfigTrustedProxiesCommand::class,
            'config allowed-origins'    => ConfigAllowedOriginsCommand::class,
            'config debugfile'          => ConfigDebugfileCommand::class,
            'config migrate'            => ConfigMigrateCommand::class,
        ];
    }

    /**
     * @param string[] $argv the full argv, including the script name at [0]
     * @return int exit code
     */
    public function run(array $argv): int {
        $args = array_slice($argv, 1);

        if ($args === [] || $args[0] === '--help' || $args[0] === 'help') {
            fwrite(STDOUT, $this->overview());
            return 0;
        }

        if ($args === ['--version']) {
            fwrite(STDOUT, 'eppitnic ' . APP_VERSION . "\n");
            return 0;
        }

        [$verb, $rest] = $this->match($args);
        if ($verb === null) {
            fwrite(STDERR, "Unknown command: " . implode(' ', $args) . "\n\n");
            fwrite(STDERR, $this->overview());
            return SYNTAX_ERROR;
        }

        $class = self::commands()[$verb];

        try {
            $command = new $class($rest);
        } catch (UsageError $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n\n");
            // built with no arguments purely to render its usage
            fwrite(STDERR, (new $class())->usage($verb));
            return SYNTAX_ERROR;
        }

        if (in_array('--help', $rest, true)) {
            fwrite(STDOUT, $command->usage($verb));
            return 0;
        }

        try {
            $code = $command->run();
            $command->flush();
            return $code;
        } catch (UsageError $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n\n");
            fwrite(STDERR, $command->usage($verb));
            return SYNTAX_ERROR;
        } catch (SessionError $e) {
            fwrite(STDERR, "Registry session unavailable: " . $e->getMessage() . "\n");
            return LOGIN_FAILED;
        }
    }

    /**
     * Longest-prefix match of the leading words against a known verb.
     *
     * @param string[] $args
     * @return array{0: ?string, 1: string[]} the verb and what follows it
     */
    private function match(array $args): array {
        $commands = self::commands();

        for ($length = min(3, count($args)); $length >= 1; $length--) {
            $candidate = implode(' ', array_slice($args, 0, $length));
            if (isset($commands[$candidate])) {
                return [$candidate, array_slice($args, $length)];
            }
        }
        return [null, []];
    }

    /**
     * @return string the command list, grouped by first word
     */
    public function overview(): string {
        $text = "Usage: eppitnic <command> [options] [arguments]\n\n";

        $groups = [];
        foreach (self::commands() as $verb => $class) {
            $groups[explode(' ', $verb)[0]][$verb] = (new $class())->describe();
        }

        foreach ($groups as $group => $verbs) {
            $text .= "{$group}\n";
            foreach ($verbs as $verb => $description) {
                $text .= sprintf("  %-30s %s\n", $verb, $description);
            }
            $text .= "\n";
        }

        $text .= "Run 'eppitnic <command> --help' for the options of one command.\n";
        $text .= "Run 'eppitnic --version' for the version.\n";
        return $text;
    }
}
