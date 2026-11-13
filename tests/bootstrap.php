<?php

/**
 * Shared setup for the module tests: WHMCS stand-ins (database, Module Log,
 * Carbon), a recording API client and the assertion helpers. Every test file
 * requires this once, so all tests can run in one process (tests/run.php).
 * Nothing here ships with the module.
 */

namespace WHMCS\Database {

    /**
     * In-memory stand-in for the WHMCS query builder. Reads go to
     * Capsule::$reader (table, conditions, operation); writes are recorded in
     * Capsule::$writes.
     */
    class Capsule
    {
        /** @var callable|null */
        public static $reader = null;
        public static array $writes = [];

        public static function table(string $table): \TestQuery
        {
            return new \TestQuery($table);
        }

        public static function reset(): void
        {
            self::$reader = null;
            self::$writes = [];
        }
    }
}

namespace Carbon {

    class Carbon
    {
        public static function now(): string
        {
            return 'NOW';
        }
    }
}

namespace {

    use ModulesGarden\AdminBolt\Actions\AbstractAction;
    use ModulesGarden\AdminBolt\Api\AdminBolt;
    use WHMCS\Database\Capsule;

    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    if(!defined('WHMCS'))
    {
        define('WHMCS', true);
    }

    require_once __DIR__ . '/../modules/servers/AdminBolt/vendor/autoload.php';
    require_once __DIR__ . '/../modules/servers/AdminBolt/AdminBolt.php';

    class TestQuery
    {
        public array $wheres = [];

        public function __construct(public string $table) {}

        public function join(...$args): static
        {
            return $this;
        }

        public function where(string $column, mixed $operator = null, mixed $value = null): static
        {
            $this->wheres[$column] = $value;
            return $this;
        }

        public function get(array $columns = []): array
        {
            return $this->read('get') ?? [];
        }

        public function first(array $columns = []): ?object
        {
            return $this->read('first');
        }

        public function update(array $data): int
        {
            Capsule::$writes[] = ['update', $this->table, $this->wheres, $data];
            return 1;
        }

        public function insert(array $data): bool
        {
            Capsule::$writes[] = ['insert', $this->table, $this->wheres, $data];
            return true;
        }

        private function read(string $operation): mixed
        {
            return Capsule::$reader ? (Capsule::$reader)($this->table, $this->wheres, $operation) : null;
        }
    }

    function logModuleCall($module, $action, $request, $response, $processed = null, $replace = []): void
    {
        $GLOBALS['moduleLog'][] = [$action, $request, $response];
    }

    /**
     * Panel API client that records every call as ["METHOD /endpoint", body]
     * and answers through $handler(method, endpoint, body), which may throw.
     */
    class TestApi extends AdminBolt
    {
        public array $calls = [];

        /** @var callable|null */
        public $handler;

        public function __construct(?callable $handler = null)
        {
            $this->handler = $handler;
        }

        protected function request(string $method, string $endpoint, ?array $content = null): ?array
        {
            $this->calls[] = [$method . ' ' . $endpoint, $content];

            return $this->handler ? ($this->handler)($method, $endpoint, $content) : null;
        }

        public function endpoints(): array
        {
            return array_column($this->calls, 0);
        }
    }

    /**
     * Gives the action a ready API client instead of one built from the server settings.
     */
    function with_api(AbstractAction $action, AdminBolt $api): AbstractAction
    {
        (function() use ($api) { $this->api = $api; })->call($action);
        return $action;
    }

    /**
     * Runs the call and returns the message of what it threw, or null.
     */
    function error_of(callable $call): ?string
    {
        try
        {
            $call();
        }
        catch(\Throwable $e)
        {
            return $e->getMessage();
        }

        return null;
    }

    $GLOBALS['tests'] = $GLOBALS['tests'] ?? ['assertions' => 0, 'failures' => 0];

    function check(bool $condition, string $label): void
    {
        $GLOBALS['tests']['assertions']++;

        if(!$condition)
        {
            $GLOBALS['tests']['failures']++;
            echo "FAIL: $label\n";
        }
    }

    /**
     * Clean state at the start of a test file.
     */
    function tests_reset(): void
    {
        Capsule::reset();
        $GLOBALS['moduleLog'] = [];
    }

    /**
     * End of a test file: when the file is run on its own, print the result
     * and exit with its status; under tests/run.php the runner reports.
     */
    function tests_done(): void
    {
        if(defined('TESTS_RUNNER'))
        {
            return;
        }

        $t = $GLOBALS['tests'];
        echo ($t['failures'] === 0 ? 'OK' : 'FAILED') . " ({$t['assertions']} assertions, {$t['failures']} failures)\n";
        exit($t['failures'] === 0 ? 0 : 1);
    }

    tests_reset();
}
