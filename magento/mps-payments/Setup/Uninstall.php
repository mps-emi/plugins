<?php
declare(strict_types=1);

namespace Mps\Payments\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Removes the module's settings on bin/magento module:uninstall. Orders and their MPS references stay:
 * they are the shop's records, not the module's.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->getConnection()->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'payment/mps_payments/%']);
    }
}
