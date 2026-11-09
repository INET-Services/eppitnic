<?php

namespace Eppitnic\Persistence;

/**
 * The credential id is registered already. Its own type, like UsernameTaken,
 * so a caller picks its own answer without parsing text.
 *
 * @category    Net
 * @package     Eppitnic\Persistence\PasskeyExists
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class PasskeyExists extends \RuntimeException
{
}
