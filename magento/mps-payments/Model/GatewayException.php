<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * The MPS gateway refused a call or could not be reached. Its message is for the log and the admin, never
 * for the shopper.
 */
class GatewayException extends \RuntimeException
{
}
