<?php
/**
 * The few Magento pieces Model/Api.php, Model/Config.php, Model/Refunder.php, Model/System/MissingApiKey.php and
 * Setup/Patch/Data/RemoveClientCredentials.php touch, for tests/run.php: a scope config read from an array, a
 * cache in an array, an HTTP client that records each request and answers from a queue of [status, body] (or
 * throws a queued exception), a store list, a setup whose connection records deletes, an encryptor, Phrase, __()
 * and LocalizedException.
 */

declare(strict_types=1);

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        public function getValue($path, $scopeType = 'default', $scopeCode = null);
    }
}

namespace Magento\Store\Model {
    interface ScopeInterface
    {
        public const SCOPE_STORE = 'store';
    }

    /** The shop's stores, by id. */
    class StoreManagerInterface
    {
        public function __construct(private readonly array $storeIds)
        {
        }

        public function getStores($withDefault = false, $codeKey = false): array
        {
            return array_map(static fn (int $id) => new class ($id) {
                public function __construct(private readonly int $id)
                {
                }

                public function getId(): int
                {
                    return $this->id;
                }
            }, $this->storeIds);
        }
    }
}

namespace Magento\Framework\Notification {
    interface MessageInterface
    {
        public const SEVERITY_CRITICAL = 1;
        public const SEVERITY_MAJOR = 2;
        public const SEVERITY_MINOR = 3;
        public const SEVERITY_NOTICE = 4;

        public function getIdentity();

        public function isDisplayed();

        public function getText();

        public function getSeverity();
    }
}

namespace Magento\Framework\Encryption {
    /** "Decrypts" a value stored as encrypted:<plain>. */
    class EncryptorInterface
    {
        public function decrypt($data)
        {
            return str_starts_with((string) $data, 'encrypted:') ? substr((string) $data, strlen('encrypted:')) : '';
        }
    }
}

namespace Magento\Framework\Setup\Patch {
    interface DataPatchInterface
    {
        public static function getDependencies();

        public function getAliases();

        public function apply();
    }
}

namespace Magento\Framework\Setup {
    /** A setup over core_config_data rows: a select answers the rows whose path it asks for; deletes are recorded. */
    class ModuleDataSetupInterface
    {
        public object $connection;

        public function __construct(array $rows)
        {
            $this->connection = new class ($rows) {
                public array $deleted = [];

                public function __construct(private readonly array $rows)
                {
                }

                public function select(): object
                {
                    return new class {
                        public string $table = '';
                        public array $paths = [];

                        public function from($name, $cols = '*'): static
                        {
                            $this->table = $name;
                            return $this;
                        }

                        public function where($cond, $value = null): static
                        {
                            $this->paths = (array) $value;
                            return $this;
                        }
                    };
                }

                public function fetchAll($select): array
                {
                    return array_values(array_filter($this->rows, static fn (array $row) => in_array($row['path'], $select->paths, true)));
                }

                public function delete($table, $where = ''): int
                {
                    $this->deleted[] = [$table, $where];
                    return 0;
                }
            };
        }

        public function getConnection(): object
        {
            return $this->connection;
        }

        public function getTable($tableName): string
        {
            return $tableName;
        }
    }
}

namespace Magento\Framework\App {
    class CacheInterface
    {
        public array $entries = [];

        public function load($identifier)
        {
            return $this->entries[$identifier] ?? false;
        }

        public function save($data, $identifier, $tags = [], $lifeTime = null)
        {
            $this->entries[$identifier] = $data;
        }

        public function remove($identifier)
        {
            unset($this->entries[$identifier]);
        }
    }
}

namespace Magento\Framework\HTTP\Client {
    class Curl
    {
        public array $headers = [];
        public string $method = '';
        public string $url = '';
        public mixed $body = null;
        private int $status = 0;
        private string $responseBody = '';

        public function __construct(private readonly CurlFactory $factory)
        {
        }

        public function setTimeout($value): void
        {
        }

        public function addHeader($name, $value): void
        {
            $this->headers[$name] = $value;
        }

        public function get($uri): void
        {
            $this->send('GET', $uri, null);
        }

        public function post($uri, $params): void
        {
            $this->send('POST', $uri, $params);
        }

        public function getStatus(): int
        {
            return $this->status;
        }

        public function getBody(): string
        {
            return $this->responseBody;
        }

        private function send(string $method, string $url, mixed $body): void
        {
            [$this->method, $this->url, $this->body] = [$method, $url, $body];
            $this->factory->requests[] = $this;
            $answer = array_shift($this->factory->answers);
            // An exception in the queue is a gateway that does not answer (timeout, connection refused).
            if ($answer instanceof \Exception) {
                throw $answer;
            }
            [$this->status, $this->responseBody] = $answer;
        }
    }

    class CurlFactory
    {
        /** @var Curl[] */
        public array $requests = [];

        /** @var array<array{int, string}|\Exception> */
        public array $answers = [];

        public function create(): Curl
        {
            return new Curl($this);
        }
    }
}

namespace Magento\Framework {
    /** Magento's translatable text: %1, %2 … replaced by the arguments. */
    class Phrase
    {
        public function __construct(private readonly string $text, private readonly array $arguments = [])
        {
        }

        public function getText(): string
        {
            return $this->text;
        }

        public function render(): string
        {
            $text = $this->text;
            foreach (array_reverse($this->arguments, true) as $i => $argument) {
                $text = str_replace('%' . ($i + 1), (string) $argument, $text);
            }

            return $text;
        }

        public function __toString(): string
        {
            return $this->render();
        }
    }
}

namespace Magento\Framework\Exception {
    use Magento\Framework\Phrase;

    class LocalizedException extends \Exception
    {
        public function __construct(private readonly Phrase $phrase, ?\Exception $cause = null, $code = 0)
        {
            parent::__construct($phrase->render(), (int) $code, $cause);
        }

        public function getRawMessage(): string
        {
            return $this->phrase->getText();
        }
    }
}

namespace {
    if (!function_exists('__')) {
        function __(...$arguments): \Magento\Framework\Phrase
        {
            $text = array_shift($arguments);

            return new \Magento\Framework\Phrase((string) $text, $arguments);
        }
    }
}

namespace Mps\Payments\Model {
    class DebugLog
    {
        /** Every line logged, for the tests that check nothing secret is written. */
        public static array $lines = [];

        public function log(string $message, ?int $storeId, string $level = 'info'): void
        {
            self::$lines[] = $message;
        }
    }
}
