<?php
declare(strict_types=1);

namespace Mps\Payments\Setup\Patch\Data;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Mps\Payments\Model\Config;

/**
 * 1.0.0: the module connects with an API key only. Removes the client ID and secret of an older setup from every
 * scope of core_config_data, and the access tokens they bought from Magento's cache, so no credential of the old
 * setup lingers in the shop. A store left without an API key is then not configured: the admin says so
 * (Model\System\MissingApiKey). Values set in app/etc/env.php or the environment are not in the database; the
 * module no longer reads them.
 */
class RemoveClientCredentials implements DataPatchInterface
{
    private const CLIENT_ID = 'payment/mps_payments/client_id';
    private const CLIENT_SECRET = 'payment/mps_payments/client_secret';
    private const GATEWAY_URL = 'payment/mps_payments/gateway_url';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly CacheInterface $cache,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $table = $this->setup->getTable('core_config_data');

        $values = [self::CLIENT_ID => [], self::CLIENT_SECRET => [], self::GATEWAY_URL => [Config::DEFAULT_GATEWAY_URL]];
        $select = $connection->select()->from($table, ['path', 'value'])->where('path IN (?)', array_keys($values));
        foreach ($connection->fetchAll($select) as $row) {
            $value = trim((string) (self::CLIENT_SECRET === $row['path'] ? $this->encryptor->decrypt((string) $row['value']) : $row['value']));
            $values[$row['path']][] = self::GATEWAY_URL === $row['path'] ? rtrim($value, '/') : $value;
        }

        // A token was cached under its gateway, client and secret (Model\Api before 1.0.0). Which scope used which
        // is not worth working out: every combination is removed, and a key nobody used is no harm.
        foreach (array_unique($values[self::GATEWAY_URL]) as $gateway) {
            foreach (array_unique($values[self::CLIENT_ID]) as $clientId) {
                foreach (array_unique($values[self::CLIENT_SECRET]) as $clientSecret) {
                    $this->cache->remove('mps_payments_token_' . hash('sha256', $gateway . '|' . $clientId . '|' . $clientSecret));
                }
            }
        }

        $connection->delete($table, ['path IN (?)' => [self::CLIENT_ID, self::CLIENT_SECRET]]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
