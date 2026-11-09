<?php

namespace Eppitnic\Support;

/**
 * The models an admin can pick for device_bound passkeys, each with the AAGUIDs
 * FIDO MDS3 lists for it. Regenerate with tools/authenticator-models.php, which
 * holds the matching rules. Synced providers are not device-bound: not here.
 *
 * @category    Net
 * @package     Eppitnic\Support\AuthenticatorModels
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class AuthenticatorModels
{
    public const SECURITY_KEYS = 'security_keys';
    public const BUILTIN = 'builtin';

    public const GROUPS = [self::SECURITY_KEYS, self::BUILTIN];

    // BEGIN GENERATED
    private const MODELS = [
        'yubikey5' => [
            'group'   => 'security_keys',
            'label'   => 'YubiKey 5 Series',
            'aaguids' => [
                '03012cb7-4fb2-42e7-9e8d-a81f10e2a5e9',
                '0a357157-9b18-4c8a-920e-d156e972b2f8',
                '0ebd9f2c-f685-441c-8c3e-a02a234a840a',
                '19083c3d-8383-4b18-bc03-8f1c9ab2fd1b',
                '1ac71f64-468d-4fe0-bef1-0e5f2f551f18',
                '20ac7a17-c814-4833-93fe-539f0d5e3389',
                '24673149-6c86-42e7-98d9-433fb5b73296',
                '2fc0579f-8113-47ea-b116-bb5a8db9202a',
                '3a662962-c6d4-4023-bebb-98ae92e78e20',
                '3aa78eb1-ddd8-46a8-a821-8f8ec57a7bd5',
                '3b24bf49-1d45-4484-a917-13175df0867b',
                '3ec9c8d3-a5a7-415b-a7b5-f1d606368d3f',
                '41e39911-c669-4811-b860-c6ad0b411b96',
                '4599062e-6926-4fe7-9566-9e8fb1aedaa0',
                '4fc84f16-2545-4e53-b8fc-7bf4d7282a10',
                '524de2de-982f-49b4-a769-2b5e3b73ad79',
                '57f7de54-c807-4eab-b1c6-1c9be7984e92',
                '662ef48a-95e2-4aaa-a6c1-5b9c40375824',
                '6ab56fad-881f-4a43-acb2-0be065924522',
                '73bb0cd4-e502-49b8-9c6f-b59445bf720b',
                '79f3c8ba-9e35-484b-8f47-53a5a0f5c630',
                '7b96457d-e3cd-432b-9ceb-c9fdd7ef7432',
                '7dab85a5-d16d-4eaf-a7ef-4c1385b151c5',
                '85203421-48f9-4355-9bc8-8a53846e5083',
                '905b4cb4-ed6f-4da9-92fc-45e0d4e9b5c7',
                '9a3f2abd-a73d-439c-9ee7-1b53a857eaa7',
                '9eb7eabc-9db5-49a1-b6c3-555a802093f4',
                'a02167b9-ae71-4ac7-9a07-06432ebb6f1c',
                'a25342c0-3cdc-4414-8e46-f4807fca511c',
                'b2c1a50b-dad8-4dc7-ba4d-0ce9597904bc',
                'b90e7dc1-316e-4fee-a25a-56a666a670fe',
                'c1f9a0bc-1dd2-404a-b27f-8e29047a43fd',
                'c3479970-e58a-4f70-836f-853bf42fb063',
                'c5ef55ff-ad9a-4b9f-b580-adebafe026d0',
                'cb69481e-8ff7-4039-93ec-0a2729a154a8',
                'd7781e5d-e353-46aa-afe2-3ca49f13332a',
                'eb7ef748-cbe0-4b40-b8f6-07bd2d592d35',
                'ee882879-721c-4913-9775-3dfcce97072a',
                'f4ce5fc0-57d3-46f5-a736-efb7d5bc63b5',
                'fa2b99dc-9e39-4257-8f92-4a30d23c4118',
                'fcc0118f-cd45-435b-8da1-9782b2da0715',
                'ff4dac45-ede8-4ec2-aced-cf66103f4335',
            ],
        ],
        'yubikey_bio' => [
            'group'   => 'security_keys',
            'label'   => 'YubiKey Bio Series',
            'aaguids' => [
                '34744913-4f57-4e6e-a527-e9ec3c4b94e6',
                '58276709-bb4b-4bb3-baf1-60eea99282a7',
                '6ec5cff2-a0f9-4169-945b-f33b563f7b99',
                '7409272d-1ff9-4e10-9fc9-ac0019c124fd',
                '7d1351a6-e097-4852-b8bf-c9ac5c9ce4a3',
                '83c47309-aabb-4108-8470-8be838b573cb',
                '8c39ee86-7f9a-4a95-9ba3-f6b097e5c2ee',
                '90636e1f-ef82-43bf-bdcf-5255f139d12f',
                '97e6a830-c952-4740-95fc-7c78dc97ce47',
                '9806a2c8-c0da-478e-b4ca-620005d34182',
                '9dd8d593-2213-438a-97f8-d6b813d51c27',
                'ad08c78a-4e41-49b9-86a2-ac15b06899e2',
                'add92433-0d69-4026-8166-29b25bce64e9',
                'ba0a9266-40d8-4048-9786-d710b5474752',
                'd8522d9f-575b-4866-88a9-ba99fa02f35b',
                'dc5e949d-f939-43b3-9877-a85c7186b753',
                'dd86a2da-86a0-4cbe-b462-4bd31f57bc6f',
            ],
        ],
        'yubico_security_key' => [
            'group'   => 'security_keys',
            'label'   => 'Security Key by Yubico',
            'aaguids' => [
                '0bb43545-fd2c-4185-87dd-feb0b2916ace',
                '0f083f18-4105-43a8-ad69-24e812e38141',
                '149a2021-8ef6-4133-96b8-81f8d5b7f1f5',
                '24083bcb-3034-4867-99de-a3b52e1d426a',
                '47ab2fb4-66ac-4184-9ae1-86be814012d5',
                '6d44ba9b-f6ec-2e49-b930-0c8fe920cb73',
                '72c6b72d-8512-4c66-8359-9d3d10d9222f',
                '9ff4cc65-6154-4fff-ba09-9e2af7882ad2',
                'a4e9fc6d-4cbe-4758-b8ba-37598bb5bbaa',
                'ab7d1767-3fa0-4388-b6c4-feef7a844809',
                'b7d3f68e-88a6-471e-9ecf-2df26d041ede',
                'b92c3f9a-c014-4056-887f-140a2501163b',
                'e77e3c64-05e3-428b-8824-0cbeb04b829d',
                'ed042a3a-4b22-4455-bb69-a267b652ae7e',
                'f8a011f3-8c0a-4d15-8006-17111f9edc7d',
            ],
        ],
        'google_titan' => [
            'group'   => 'security_keys',
            'label'   => 'Google Titan Security Key',
            'aaguids' => [
                '42b4fb4a-2866-43b2-9bf7-6c6669c2e5d3',
            ],
        ],
        'feitian' => [
            'group'   => 'security_keys',
            'label'   => 'Feitian',
            'aaguids' => [
                '12755c32-8ad1-46eb-881c-e0b38d848b09',
                '12ded745-4bed-47d4-abaa-e713f51d6393',
                '234cd403-35a2-4cc2-8015-77ea280c77f5',
                '238ab2f5-b57f-4917-b3c6-3d3c6c0c350f',
                '260e3021-482d-442d-838c-7edfbe153b7e',
                '2bff89f2-323a-48fc-b7c8-9ff7fe87c07e',
                '2c0df832-92de-4be1-8412-88a8f074df4a',
                '39589099-9a75-49fc-afaa-801ca211c62a',
                '3e22415d-7fdf-4ea4-8a0c-dd60c4249b9d',
                '42df17de-06ba-4177-a2bb-6701be1380d6',
                '4c0cf95d-2f40-43b5-ba42-4c83a11c04ba',
                '77010bd7-212a-4fc9-b236-d2ca5e9d4084',
                '78ba3993-d784-4f44-8d6e-cc0a8ad5230e',
                '833b721a-ff5f-4d00-bb2e-bdda3ec01e29',
                'a02140b7-0cbd-42e1-a9b5-a39da2545114',
                'b6ede29c-3772-412c-8a78-539c1f4c62d2',
                'ee041bce-25e5-4cdb-8f86-897fd6418464',
            ],
        ],
        'token2' => [
            'group'   => 'security_keys',
            'label'   => 'TOKEN2',
            'aaguids' => [
                'ab32f0c6-2239-afbb-c470-d2ef4e254db7',
                'eabb46cc-e241-80bf-ae9e-96fa6d2975cf',
            ],
        ],
        'swissbit' => [
            'group'   => 'security_keys',
            'label'   => 'Swissbit iShield',
            'aaguids' => [
                '5d629218-d3a5-11ed-afa1-0242ac120002',
                '5eaff75a-dd43-451f-af9f-87c9eeae293e',
                '7787a482-13e8-4784-8a06-c7ed49a7aaf4',
                '817cdab8-0d51-4de1-a821-e25b88519cf3',
                '931327dd-c89b-406c-a81e-ed7058ef36c6',
                'e400ef8c-711d-4692-af46-7f2cf7da23ad',
            ],
        ],
        'nitrokey' => [
            'group'   => 'security_keys',
            'label'   => 'Nitrokey',
            'aaguids' => [
                '2cd2f727-f6ca-44da-8f48-5c2e5da000a2',
            ],
        ],
        'solokeys' => [
            'group'   => 'security_keys',
            'label'   => 'SoloKeys',
            'aaguids' => [
                '8876631b-d4a0-427f-5773-0ec71c9e0279',
                '8976631b-d4a0-427f-5773-0ec71c9e0279',
            ],
        ],
        'thales' => [
            'group'   => 'security_keys',
            'label'   => 'Thales / SafeNet',
            'aaguids' => [
                '04a8fcf2-19c1-457b-911e-69219f17583f',
                '050dd0bc-ff20-4265-8d5d-305c4b215192',
                '0fbb74b5-f1ac-4f14-a0f1-ec39b5edfdae',
                '10c70715-2a9a-4de1-b0aa-3cff6d496d39',
                '11544c3c-3693-40ba-9dbb-3b8d2b977988',
                '146e77ef-11eb-4423-b847-ce77864e9411',
                '23786452-f02d-4344-87ed-aaf703726881',
                '33d6d7d0-279f-4ef3-96b3-2d3282f4bde6',
                '4d41190c-7beb-4a84-8018-adf265a6352d',
                '74820b05-a6c9-40f9-8fb0-9f86aca93998',
                '9152406a-de8e-46dd-8685-3875c067e486',
                'a6c5f5d8-2ad0-48b6-8257-e502c8970931',
                'b113a455-cfb6-4c17-8cba-cd952feb7d48',
                'c3f47802-de73-4dfc-ba22-671fe3304f90',
                'd716019a-9f4e-4041-9750-17c78f8ae81a',
                'efb96b10-a9ee-4b6c-a4a9-d32125ccd4a4',
            ],
        ],
        'windows_hello' => [
            'group'   => 'builtin',
            'label'   => 'Windows Hello',
            'aaguids' => [
                '08987058-cadc-4b81-b6e1-30de50dcbe96',
                '9ddd1817-af5a-4672-a2b9-3e3dd95000a9',
            ],
        ],
    ];
    // END GENERATED

    /** @return string[] every model key, in catalogue order */
    public static function keys(): array {
        return array_keys(self::MODELS);
    }

    public static function has(string $key): bool {
        return isset(self::MODELS[$key]);
    }

    /**
     * @param string[] $keys model keys; unknown ones name nothing
     * @return string[] their AAGUIDs, lower case
     */
    public static function aaguids(array $keys): array {
        $aaguids = [];
        foreach ($keys as $key) {
            $aaguids = [...$aaguids, ...(self::MODELS[$key]['aaguids'] ?? [])];
        }
        return array_values(array_unique($aaguids));
    }

    /**
     * @return array<string, array<int, array{key: string, label: string}>> by group
     */
    public static function catalogue(): array {
        $catalogue = array_fill_keys(self::GROUPS, []);
        foreach (self::MODELS as $key => $model) {
            $catalogue[$model['group']][] = ['key' => $key, 'label' => $model['label']];
        }
        return $catalogue;
    }

    /** @return string|null the label of the first model that lists the AAGUID */
    public static function labelFor(string $aaguid): ?string {
        $aaguid = strtolower($aaguid);
        foreach (self::MODELS as $model) {
            if (in_array($aaguid, $model['aaguids'], true)) {
                return $model['label'];
            }
        }
        return null;
    }
}
