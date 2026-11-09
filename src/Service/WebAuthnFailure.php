<?php

namespace Eppitnic\Service;

/**
 * A WebAuthn ceremony that did not verify. The reason is a code to record or
 * branch on, never the library's text; its exception stays in getPrevious() for
 * the server log only.
 *
 * @category    Net
 * @package     Eppitnic\Service\WebAuthnFailure
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class WebAuthnFailure extends \RuntimeException
{
    public const DISABLED = 'disabled';
    /** device_bound without MDS3 metadata that is current */
    public const METADATA = 'metadata';
    public const METADATA_MESSAGE = 'Device-bound passkeys cannot be registered: the FIDO metadata is missing or out of date';
    /** not a ceremony failure: the user holds as many keys as they may */
    public const LIMIT = 'limit';
    public const MALFORMED = 'malformed';
    public const CHALLENGE = 'challenge';
    public const TYPE = 'type';
    public const ORIGIN = 'origin';
    public const CROSS_ORIGIN = 'cross_origin';
    public const RP_ID_HASH = 'rp_id_hash';
    public const USER_PRESENCE = 'user_presence';
    public const USER_VERIFICATION = 'user_verification';
    public const ALGORITHM = 'algorithm';
    public const ATTESTATION = 'attestation';
    public const CREDENTIAL_ID = 'credential_id';
    public const BACKUP_FLAGS = 'backup_flags';
    public const SIGNATURE = 'signature';
    public const USER_HANDLE = 'user_handle';
    /** the counter did not go forward: a cloned authenticator, or a replay */
    public const COUNTER_REGRESSION = 'counter_regression';
    public const INVALID = 'invalid';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null) {
        parent::__construct($reason, 0, $previous);
    }
}
