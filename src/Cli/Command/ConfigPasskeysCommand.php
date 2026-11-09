<?php

namespace Eppitnic\Cli\Command;

use Eppitnic\Cli\Command;
use Eppitnic\Cli\UsageError;
use Eppitnic\Persistence\History;
use Eppitnic\Service\PasskeyImpact;
use Eppitnic\Service\PasskeyMetadata;
use Eppitnic\Service\PasskeysInUse;
use Eppitnic\Service\PasskeySettings;

/**
 * Show or set the `passkeys` setting -- see PasskeySettings, the one place
 * that validates it (also used by PUT /v1/settings/passkeys). A new or cleared
 * rp_id deletes every registered passkey, which takes --confirm. Unlike the
 * API it does not refuse a change that locks every admin out: it is the way
 * back in, and says so.
 */
final class ConfigPasskeysCommand extends Command
{
    public function describe(): string {
        return 'show the passkey settings, or set the relying party and policy';
    }

    public function options(): array {
        return [
            'rp-id='        => 'the host name passkeys are bound to; empty switches passkeys off',
            'origin='       => 'an https origin at or below the rp-id; repeat for several, empty for none',
            'type='         => 'any or device_bound',
            'model='        => 'an accepted authenticator model; repeat for several, empty for none',
            'extra-aaguid=' => 'an accepted AAGUID; repeat for several, empty for none',
            'confirm'       => 'accept deleting the registered passkeys a new rp-id outdates',
        ];
    }

    public function run(): int {
        $this->database();

        $changes = [];
        foreach (['rp-id' => 'rp_id', 'type' => 'type'] as $option => $field) {
            if ($this->hasOption($option)) {
                $changes[$field] = (string) $this->option($option);
            }
        }
        foreach (['origin' => 'origins', 'model' => 'models', 'extra-aaguid' => 'extra_aaguids'] as $option => $field) {
            if ($this->hasOption($option)) {
                $changes[$field] = array_values(array_filter($this->optionValues($option), static fn(string $v): bool => $v !== ''));
            }
        }

        if ($changes !== []) {
            try {
                $impact = PasskeyImpact::of(PasskeySettings::preview($changes)[1]);
                PasskeySettings::set($changes, $this->userId(), $this->hasOption('confirm'));
            } catch (PasskeysInUse $e) {
                $this->warn($e->getMessage() . ' (--confirm)');
                return INVALID_INPUT;
            } catch (\InvalidArgumentException $e) {
                throw new UsageError($e->getMessage());
            }
            $counts = PasskeyImpact::counts($impact);
            History::record('security', $this->userId(), 'secread', [
                'event' => 'passkey_policy_changed', 'changes' => array_keys($changes), 'by' => 'cli',
            ] + $counts, $this->userId());
            if ($counts['locked_out'] > 0) {
                $this->warn("{$counts['locked_out']} user(s) are locked out by this; 'user signin-reset' is the way back"
                    . ($counts['admins_able_after'] === 0 ? ' -- and no active admin can sign in' : ''));
            }
        }

        $settings = PasskeySettings::get();
        $inUse = PasskeySettings::inUse();
        $metadata = PasskeyMetadata::status();
        $list = static fn(array $items): string => $items === [] ? '-' : implode(', ', $items);
        $this->record(
            "rp_id:         " . ($settings['rp_id'] === '' ? '- (passkeys are off)' : $settings['rp_id']) . "\n"
            . "origins:       " . $list($settings['origins']) . "\n"
            . "type:          {$settings['type']}\n"
            . "models:        " . $list($settings['models']) . "\n"
            . "extra_aaguids: " . $list($settings['extra_aaguids']) . "\n"
            . "registered:    {$inUse['passkeys']} passkey(s); {$inUse['users']} user(s) need one\n"
            . "metadata:      {$metadata['state']}" . ($metadata['next_update'] === null ? '' : ", serial {$metadata['serial']}, next update {$metadata['next_update']}"),
            $settings + ['in_use' => $inUse, 'metadata' => $metadata]
        );
        return 0;
    }
}
