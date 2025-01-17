<?php

namespace Nodes\Bugsnag;

use Bugsnag\BugsnagLaravel\LaravelLogger;
use Bugsnag\BugsnagLaravel\Request\LaravelResolver;
use Bugsnag\Client;
use Bugsnag\Configuration;
use Bugsnag\PsrLogger\MultiLogger;
use Bugsnag\Report;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Nodes\Bugsnag\Exceptions\Handler as BugsnagHandler;

/**
 * Class ServiceProvider.
 */
class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * Nodes Bugsnag version.
     *
     * @const string
     */
    const VERSION = '2.0';

    /**
     * Bootstrap the application service.
     *
     * @return void
     * @author Morten Rugaard <moru@nodes.dk>
     */
    public function boot()
    {
        $this->registerHandler();

        // Register publish groups
        $this->publishGroups();

        // Failed jobs listener
        $this->registerFailedJobsListener();
    }

    /**
     * Register the service provider.
     *
     * @return void
     * @author Morten Rugaard <moru@nodes.dk>
     */
    public function register()
    {
        $this->registerBugsnag();
    }

    /**
     * Register publish groups.
     *
     * @return void
     * @author Morten Rugaard <moru@nodes.dk>
     */
    protected function publishGroups()
    {
        // Config files
        $this->publishes([
            __DIR__ . '/../config/bugsnag.php' => config_path('nodes/bugsnag.php'),
        ], 'config');
    }

    /**
     * Register Bugsnag instance.
     *
     * @return void
     * @author Rasmus Ebbesen <re@nodes.dk>
     * @author Morten Rugaard <moru@nodes.dk>
     */
    protected function registerBugsnag()
    {
        $config = config('nodes.bugsnag');

        if (!in_array($this->app->environment(), config('nodes.bugsnag.notify_release_stages', []))) {
            return;
        }

        $this->app->singleton('nodes.bugsnag', function (Container $app) use ($config) {
            // Retrieve bugsnag settings
            $bugsnag = new Client(new Configuration($config['api_key']), new LaravelResolver($app),
                $this->getGuzzle($config));
            $this->setupPaths($bugsnag, $app->basePath(), $app->path(), base_path(), app_path());
            $bugsnag->setReleaseStage($app->environment());
            $bugsnag->setBatchSending(false);
            $bugsnag->setNotifier([
                'name'    => 'Nodes Bugsnag Laravel',
                'version' => self::VERSION,
                'url'     => 'http://packagist.com/nodes/bugsnag',
            ]);

            // Set notify release stages
            if (!empty($config['notify_release_stages'])) {
                $bugsnag->setNotifyReleaseStages((array)$config['notify_release_stages']);
            }

            // Set filters
            if (!empty($config['filters'])) {
                $bugsnag->setFilters((array)$config['filters']);
            }

            $bugsnag->registerDefaultCallbacks();

            // Attach user agent data to all exceptions
            $bugsnag->registerCallback(function (Report $report) {
                $report->setMetaData(['User Agent' => $this->gatherUserAgentData()]);
            });

            return $bugsnag;
        });

        $this->app->singleton('bugsnag.logger', function (Container $app) {
            return new LaravelLogger($app['nodes.bugsnag']);
        });

        $this->app->singleton('bugsnag.multi', function (Container $app) {
            return new MultiLogger([$app['log'], $app['bugsnag.logger']]);
        });

        $this->app->alias('nodes.bugsnag', Client::class);
        $this->app->alias('bugsnag.logger', LaravelLogger::class);
        $this->app->alias('bugsnag.multi', MultiLogger::class);
    }

    /**
     * Gather user agent data.
     *
     * @return array
     * @author Morten Rugaard <moru@nodes.dk>
     */
    protected function gatherUserAgentData()
    {
        // User agent container
        $userAgents = ['original' => null, 'nodes_meta' => null];

        // Retrieve original user agent
        $originalUserAgent = user_agent();
        if (!empty($originalUserAgent)) {
            $userAgents['original'] = [
                'browser'  => $originalUserAgent->getBrowserWithVersion(),
                'platform' => $originalUserAgent->getPlatform(),
                'device'   => $originalUserAgent->getDevice(),
                'isMobile' => $originalUserAgent->isMobile(),
                'isTablet' => $originalUserAgent->isTablet(),
            ];
        }

        // Retrieve nodes user agent
        $nodesUserAgent = nodes_meta();
        if (!empty($nodesUserAgent)) {
            $userAgents['nodes_meta'] = [
                'version'  => $nodesUserAgent->getVersion(),
                'platform' => $nodesUserAgent->getPlatform(),
                'device'   => $nodesUserAgent->getDevice(),
            ];
        }

        return $userAgents;
    }

    /**
     * Get the guzzle client instance.
     * from bugsnag/bugsnag-laravel package.
     *
     * @param array $config
     * @return \GuzzleHttp\ClientInterface
     */
    protected function getGuzzle(array $config)
    {
        $options = [];
        if (isset($config['proxy']) && $config['proxy']) {
            if (isset($config['proxy']['http']) && php_sapi_name() != 'cli') {
                unset($config['proxy']['http']);
            }
            $options['proxy'] = $config['proxy'];
        }

        return Client::makeGuzzle(isset($config['endpoint']) ? $config['endpoint'] : null, $options);
    }

    /**
     * Setup the client paths.
     * from bugsnag/bugsnag-laravel package.
     *
     * @param \Bugsnag\Client $client
     * @param string          $base
     * @param string          $path
     * @param string|null     $strip
     * @param string|null     $project
     * @return void
     */
    protected function setupPaths(Client $client, $base, $path, $strip, $project)
    {
        if ($strip) {
            $client->setStripPath($strip);
            if (!$project) {
                $client->setProjectRoot("{$strip}/app");
            }

            return;
        }

        if ($project) {
            if ($base && substr($project, 0, strlen($base)) === $base) {
                $client->setStripPath($base);
            }
            $client->setProjectRoot($project);

            return;
        }

        $client->setStripPath($base);
        $client->setProjectRoot($path);
    }

    /**
     * Register an event listener to trigger
     * on failed jobs from queues.
     *
     * @return void
     * @author Rasmus Ebbesen <re@nodes.dk>
     */
    protected function registerFailedJobsListener()
    {
        if (!in_array($this->app->environment(), config('nodes.bugsnag.notify_release_stages', []))) {
            return;
        }

        if (!config('nodes.bugsnag.report_failed_jobs', true)) {
            return;
        }

        Queue::failing(function (JobFailed $event) {
            $exception = $event->exception;
            $meta = [
                'job'        => [
                    'name'     => $event->job->getName(),
                    'queue'    => $event->job->getQueue(),
                    'raw_body' => $event->job->getRawBody(),
                ],
                'connection' => [
                    'name' => $event->connectionName,
                ],
            ];

            app('nodes.bugsnag')->notifyException($exception, function (\Bugsnag\Report $report) use ($meta) {
                $report->setMetaData($meta, true);
            });
        });
    }

    /**
     * registerHandler
     * we'll re-bind the default Exception Handler to use our Bugsnag Handler
     * so exceptions will be reported to Bugsnag.
     *
     * @author Casper Rasmussen <cr@nodes.dk>
     */
    protected function registerHandler()
    {
        if (!in_array($this->app->environment(), config('nodes.bugsnag.notify_release_stages', []))) {
            return;
        }

        if (!config('nodes.bugsnag.rebind_handler', true)) {
            return;
        }

        $this->app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) {
            return app(BugsnagHandler::class);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     * @author Morten Rugaard <moru@nodes.dk>
     */
    public function provides()
    {
        return ['nodes.bugsnag', 'bugsnag.logger', 'bugsnag.multi'];
    }
}
