<?php
declare(strict_types=1);

namespace Mps\Payments\Model;

use Psr\Log\LoggerInterface;

/**
 * Writes to var/log/mps_payments.log while Debug log is on for the store, and nowhere otherwise.
 */
class DebugLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config,
    ) {
    }

    /**
     * @param string $level A PSR-3 level: info for a call that worked, warning for a refusal, error for no answer.
     */
    public function log(string $message, ?int $storeId, string $level = 'info'): void
    {
        if ($this->config->isDebug($storeId)) {
            $this->logger->log($level, $message);
        }
    }
}
