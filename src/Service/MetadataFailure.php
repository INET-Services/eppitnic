<?php

namespace Eppitnic\Service;

/**
 * A metadata BLOB that was not fetched or not trusted. The reason is a code to
 * branch on; the message says it in words for an operator.
 *
 * @category    Net
 * @package     Eppitnic\Service\MetadataFailure
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class MetadataFailure extends \RuntimeException
{
    /** the server did not answer with a BLOB */
    public const FETCH = 'fetch';
    public const MALFORMED = 'malformed';
    public const SIGNATURE = 'signature';
    /** the signing certificate does not chain to the FIDO root */
    public const CHAIN = 'chain';
    /** past its nextUpdate and the grace after it */
    public const STALE = 'stale';
    /** a lower serial than the one stored */
    public const ROLLBACK = 'rollback';

    public function __construct(public readonly string $reason, string $message) {
        parent::__construct($message);
    }
}
