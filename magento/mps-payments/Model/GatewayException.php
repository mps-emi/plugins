<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

/**
 * The MPS gateway refused a call or could not be reached. Its message is for the log and the admin, never
 * for the shopper.
 */
class GatewayException extends \RuntimeException
{
    /**
     * @param int    $code         The HTTP status the gateway answered, or 0 when it did not answer.
     * @param string $responseBody What it answered, for the caller to explain a refusal (a 400 names the field).
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly string $responseBody = '')
    {
        parent::__construct($message, $code, $previous);
    }
}
