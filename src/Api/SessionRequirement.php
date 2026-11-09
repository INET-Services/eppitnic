<?php

namespace Eppitnic\Api;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

/**
 * A 403 for a session that still owes some setup, with the fields a login
 * would have told the client besides the message.
 *
 * @category    Net
 * @package     Eppitnic\Api\SessionRequirement
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class SessionRequirement extends HttpForbiddenException
{
    /**
     * @param array $details merged into the JSON error answer
     */
    public function __construct(Request $request, string $message, public readonly array $details = []) {
        parent::__construct($request, $message);
    }
}
