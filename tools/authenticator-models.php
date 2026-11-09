<?php

// Builds the model catalogue (src/Support/AuthenticatorModels.php) from a FIDO
// MDS3 BLOB by matching entry descriptions, and prints what each rule matched.
//
//   php tools/authenticator-models.php [--file=blob.jwt] [--write]
//
// Without --file the BLOB is downloaded; either way it is verified like the
// application does before a word of it is used. Without --write the PHP array
// goes to standard output. The MDS3 terms keep the BLOB itself out of the
// repository: only the AAGUIDs and labels this prints are committed.

use Eppitnic\PowerDns\CurlHttpClient;
use Eppitnic\Service\MetadataBlob;
use Eppitnic\Service\PasskeyMetadata;

require dirname(__DIR__) . '/vendor/autoload.php';

// model key => [group, label, description rule, keyProtection it needs]
$models = [
    'yubikey5'            => ['security_keys', 'YubiKey 5 Series', '/^YubiKey 5\b/i', null],
    'yubikey_bio'         => ['security_keys', 'YubiKey Bio Series', '/^YubiKey Bio\b/i', null],
    'yubico_security_key' => ['security_keys', 'Security Key by Yubico', '/^(Enterprise )?Security Key\b/i', null],
    'google_titan'        => ['security_keys', 'Google Titan Security Key', '/\bTitan\b/i', null],
    'feitian'             => ['security_keys', 'Feitian', '/\bFeitian\b/i', null],
    'token2'              => ['security_keys', 'TOKEN2', '/\bTOKEN2\b/i', null],
    'swissbit'            => ['security_keys', 'Swissbit iShield', '/\bSwissbit\b/i', null],
    'nitrokey'            => ['security_keys', 'Nitrokey', '/\bNitrokey\b/i', null],
    'solokeys'            => ['security_keys', 'SoloKeys', '/^Solo\b/i', null],
    'thales'              => ['security_keys', 'Thales / SafeNet', '/\b(Thales|SafeNet|eToken)\b/i', null],
    // the software authenticator has no attestation root and is no hardware
    'windows_hello'       => ['builtin', 'Windows Hello', '/^Windows Hello\b/i', 'hardware'],
];
// pre-release builds, never sold
$exclude = '/\bPreview\b/i';

$options = getopt('', ['file:', 'write']);
$jwt = isset($options['file'])
    ? file_get_contents($options['file'])
    : (new CurlHttpClient(10, 180, true, 67108864))->request('GET', PasskeyMetadata::URL, [], null)['body'];
if ($jwt === false || $jwt === '') {
    fwrite(STDERR, "no BLOB\n");
    exit(1);
}
$blob = MetadataBlob::verify($jwt, (string) file_get_contents(PasskeyMetadata::ROOT_FILE), new DateTimeImmutable('now', new DateTimeZone('UTC')));
fwrite(STDERR, "BLOB {$blob->serial}, next update {$blob->nextUpdate}, " . count($blob->entries) . " entries\n");

$found = [];
$claimed = [];
foreach ($models as $key => [$group, $label, $rule, $protection]) {
    fwrite(STDERR, "\n{$key} ({$label}): {$rule}" . ($protection ? " + keyProtection {$protection}" : '') . "\n");
    foreach ($blob->entries as $aaguid => $entry) {
        $description = (string) $entry['description'];
        if (preg_match($rule, $description) !== 1 || preg_match($exclude, $description) === 1) {
            continue;
        }
        if ($protection !== null && ! in_array($protection, $entry['entry']['keyProtection'], true)) {
            fwrite(STDERR, "  skipped  {$aaguid}  {$description}  (keyProtection " . implode(',', $entry['entry']['keyProtection']) . ")\n");
            continue;
        }
        if (isset($claimed[$aaguid])) {
            fwrite(STDERR, "  CLASH    {$aaguid}  {$description}  (already {$claimed[$aaguid]})\n");
            continue;
        }
        $claimed[$aaguid] = $key;
        $found[$key][] = $aaguid;
        fwrite(STDERR, "  {$aaguid}  {$entry['status']}  {$description}\n");
    }
    sort($found[$key]);
    fwrite(STDERR, '  = ' . count($found[$key]) . " AAGUID(s)\n");
}

$php = "    private const MODELS = [\n";
foreach ($models as $key => [$group, $label]) {
    $php .= "        '{$key}' => [\n"
        . "            'group'   => '{$group}',\n"
        . "            'label'   => '{$label}',\n"
        . "            'aaguids' => [\n"
        . implode('', array_map(static fn(string $a): string => "                '{$a}',\n", $found[$key]))
        . "            ],\n"
        . "        ],\n";
}
$php .= "    ];\n";

if (isset($options['write'])) {
    $file = dirname(__DIR__) . '/src/Support/AuthenticatorModels.php';
    $source = (string) file_get_contents($file);
    $updated = preg_replace('/(\/\/ BEGIN GENERATED\n).*?(    \/\/ END GENERATED)/s', '$1' . addcslashes($php, '\\$') . '$2', $source, 1, $count);
    if ($count !== 1) {
        fwrite(STDERR, "no BEGIN/END GENERATED markers in {$file}\n");
        exit(1);
    }
    file_put_contents($file, $updated);
    fwrite(STDERR, "\nwrote {$file}\n");
} else {
    echo $php;
}
