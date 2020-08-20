<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\CakePHP\CakePHPSandboxedIntegration;
use DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration;
use DDTrace\Integrations\Curl\CurlSandboxedIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration;
use DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration;
use DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration;
use DDTrace\Integrations\Laravel\LaravelSandboxedIntegration;
use DDTrace\Integrations\Lumen\LumenSandboxedIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mongo\MongoSandboxedIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\PDO\PDOSandboxedIntegration;
use DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Predis\PredisSandboxedIntegration;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Integrations\Slim\SlimSandboxedIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Symfony\SymfonySandboxedIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\WordPress\WordPressSandboxedIntegration;
use DDTrace\Integrations\Yii\YiiSandboxedIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration;
use DDTrace\Log\LoggingTrait;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    use LoggingTrait;

    /**
     * @var IntegrationsLoader
     */
    private static $instance;

    /**
     * @var array
     */
    private $integrations = [];

    /**
     * @var array
     */
    public static $officiallySupportedIntegrations = [
        CakePHPSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\CakePHP\CakePHPSandboxedIntegration',
        CodeIgniterSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration',
        CurlSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Curl\CurlSandboxedIntegration',
        EloquentSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration',
        GuzzleSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration',
        LaravelSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Laravel\LaravelSandboxedIntegration',
        LumenSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Lumen\LumenSandboxedIntegration',
        MemcachedSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration',
        MongoSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Mongo\MongoSandboxedIntegration',
        MysqliSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration',
        PDOSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\PDO\PDOSandboxedIntegration',
        PHPRedisSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration',
        PredisSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Predis\PredisSandboxedIntegration',
        SlimSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Slim\SlimSandboxedIntegration',
        SymfonySandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Symfony\SymfonySandboxedIntegration',
        WebIntegration::NAME =>
            '\DDTrace\Integrations\Web\WebIntegration',
        WordPressSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\WordPress\WordPressSandboxedIntegration',
        YiiSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\Yii\YiiSandboxedIntegration',
        ZendFrameworkSandboxedIntegration::NAME =>
            '\DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration',
    ];

    /**
     * @var array Registry to keep track of integrations loading status.
     */
    private $loadings = [];

    /**
     * @param array|null $integrations
     */
    public function __construct(array $integrations)
    {
        $this->integrations = $integrations;
        if (\PHP_MAJOR_VERSION < 7) {
            // PHP 7+ only
            unset($this->integrations[PHPRedisSandboxedIntegration::NAME]);

            // PHP 7.0+ use C level deferred integration loader, PHP 5 doesn't
            $this->integrations[ElasticSearchSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration';
        }
    }

    /**
     * Returns the singleton integration loader.
     *
     * @return IntegrationsLoader
     */
    public static function get()
    {
        if (null === self::$instance) {
            self::$instance = new IntegrationsLoader(self::$officiallySupportedIntegrations);
        }

        return self::$instance;
    }

    /**
     * Loads all the integrations registered with this loader instance.
     */
    public function loadAll()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error(
                'Missing ddtrace extension. To disable tracing set env variable DD_TRACE_ENABLED=false',
                E_USER_WARNING
            );
            return;
        }

        if (!\ddtrace_config_trace_enabled()) {
            return;
        }

        self::logDebug('Attempting integrations load');

        foreach ($this->integrations as $name => $class) {
            if (!\ddtrace_config_integration_enabled($name)) {
                self::logDebug('Integration {name} is disabled', ['name' => $name]);
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            $integrationLoadingStatus = $this->getLoadingStatus($name);
            if (in_array($integrationLoadingStatus, [Integration::LOADED, Integration::NOT_AVAILABLE])) {
                continue;
            }

            if (strpos($class, 'SandboxedIntegration') !== false) {
                $integration = new $class();
                $this->loadings[$name] = $integration->init();
            } else {
                $this->loadings[$name] = $class::load();
            }
            $this->logResult($name, $this->loadings[$name]);
        }
    }

    /**
     * Logs a proper message to report the status of an integration loading attempt.
     *
     * @param string $name
     * @param int $result
     */
    private function logResult($name, $result)
    {
        if ($result === Integration::LOADED) {
            self::logDebug('Loaded integration {name}', ['name' => $name]);
        } elseif ($result === Integration::NOT_AVAILABLE) {
            self::logDebug('Integration {name} not available. New attempts WILL NOT be performed.', [
                'name' => $name,
            ]);
        } elseif ($result === Integration::NOT_LOADED) {
            self::logDebug('Integration {name} not loaded. New attempts might be performed.', [
                'name' => $name,
            ]);
        } else {
            self::logError('Invalid value returning by integration loader for {name}: {value}', [
                'name' => $name,
                'value' => $result,
            ]);
        }
    }

    /**
     * Returns the registered integrations.
     *
     * @return array
     */
    public function getIntegrations()
    {
        return $this->integrations;
    }

    /**
     * Returns the provide integration current loading status.
     *
     * @param string $integrationName
     * @return int
     */
    public function getLoadingStatus($integrationName)
    {
        return isset($this->loadings[$integrationName]) ? $this->loadings[$integrationName] : Integration::NOT_LOADED;
    }

    /**
     * Loads all the enabled library integrations using the global singleton integrations loader which in charge
     * only of the officially supported integrations.
     */
    public static function load()
    {
        self::get()->loadAll();
    }

    public static function reload()
    {
        self::$instance = null;
        self::load();
    }

    public function reset()
    {
        $this->integrations = [];
    }
}
