<?php namespace PragmaRX\Firewall\Vendor\Laravel;

use PragmaRX\Firewall\Firewall;

use PragmaRX\Support\Config;
use PragmaRX\Support\Filesystem;
use PragmaRX\Support\CacheManager;
use PragmaRX\Support\Response;

use PragmaRX\Firewall\Vendor\Laravel\Artisan\Whitelist as WhitelistCommand;
use PragmaRX\Firewall\Vendor\Laravel\Artisan\Blacklist as BlacklistCommand;
use PragmaRX\Firewall\Vendor\Laravel\Artisan\Report as ReportCommand;
use PragmaRX\Firewall\Vendor\Laravel\Artisan\Remove as RemoveCommand;
use PragmaRX\Firewall\Vendor\Laravel\Artisan\Clear as ClearCommand;

use PragmaRX\Firewall\Repositories\DataRepository;
use PragmaRX\Firewall\Repositories\Cache\Cache;
use PragmaRX\Firewall\Repositories\Firewall\Firewall as FirewallRepository;

use PragmaRX\Support\ServiceProvider as PragmaRXServiceProvider;

class ServiceProvider extends PragmaRXServiceProvider {

    protected $packageVendor = 'pragmarx';
    protected $packageVendorCapitalized = 'PragmaRX';

    protected $packageName = 'firewall';
    protected $packageNameCapitalized = 'Firewall';

    /**
     * This is the boot method for this ServiceProvider
     *
     * @return void
     */
    public function wakeUp()
    {

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->preRegister();

        $this->registerFileSystem();

        $this->registerCache();

        $this->registerFirewall();

        $this->registerDataRepository();

        $this->registerWhitelistCommand();
        $this->registerBlacklistCommand();
        $this->registerReportCommand();
        $this->registerRemoveCommand();
        $this->registerClearCommand();

        $this->registerFilters();

        $this->commands('firewall.whitelist.command');
        $this->commands('firewall.blacklist.command');
        $this->commands('firewall.list.command');
        $this->commands('firewall.remove.command');
        $this->commands('firewall.clear.command');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('firewall');
    }

    /**
     * Register the Filesystem driver used by Firewall
     * 
     * @return void
     */
    private function registerFileSystem()
    {
        $this->app['firewall.fileSystem'] = $this->app->share(function($app)
        {
            return new Filesystem;
        });
    }

    /**
     * Register the Cache driver used by Firewall
     * 
     * @return void
     */
    private function registerCache()
    {
        $this->app['firewall.cache'] = $this->app->share(function($app)
        {
            return new CacheManager($app);
        });
    }

    /**
     * Register the Data Repository driver used by Firewall
     * 
     * @return void
     */
    private function registerDataRepository()
    {
        $this->app['firewall.dataRepository'] = $this->app->share(function($app)
        {
            $firewallModel = $this->getConfig('firewall_model');

            return new DataRepository(
                                        new FirewallRepository(
                                                                    new $firewallModel, 
                                                                    $this->app['firewall.cache'],
                                                                    $this->app['firewall.config']
                                                            ),

                                        $this->app['firewall.config'],

                                        $this->app['firewall.cache'],

                                        $this->app['firewall.fileSystem']
                                    );
        });
    }

    /**
     * Takes all the components of Firewall and glues them
     * together to create Firewall.
     *
     * @return void
     */
    private function registerFirewall()
    {
        $this->app['firewall'] = $this->app->share(function($app)
        {
            $app['firewall.loaded'] = true;

            return new Firewall(
                                    $app['firewall.config'],
                                    $app['firewall.dataRepository'],
                                    $app['firewall.cache'],
                                    $app['firewall.fileSystem'],
                                    $app['request']
                                );
        });
    }
 
    /**
     * Register blocking and unblocking filters
     * 
     * @return void
     */
    private function registerFilters()
    {
        $this->app['router']->filter('fw-block-bl', $this->getWhitelistFilter());

        $this->app['router']->filter('fw-allow-wl', $this->getBlacklistFilter());
    }

    public function getWhitelistFilter()
    {
        return function($route) 
        {
            if ($this->app['firewall']->isBlacklisted()) {
                $this->log('[blocked] IP blacklisted: '.$this->app['firewall']->getIp());

                return $this->blockAccess();
            }
        };
    }

    public function getBlacklistFilter()
    {
        return function($route)
        {
            if ( ! $this->app['firewall']->isWhitelisted()) {
                if($to = $this->getConfig('redirect_non_whitelisted_to'))
                {
                    $action = 'redirected';
                    $response = $this->app['redirect']->to($to);
                }
                else
                {
                    $action = 'blocked';
                    $response = $this->blockAccess();
                }

                $this->log(sprintf('[%s] IP not whitelisted: %s', $action, $this->app['firewall']->getIp()));

                return $response;
            }
        };        
    }

    /**
     * Return a proper response for blocked access
     *
     * @return Response
     */ 
    private function blockAccess()
    {
        return Response::make(
                                $this->getConfig('block_response_message'), 
                                $this->getConfig('block_response_code')
                            );    
    }

    /**
     * Register messages in log
     *
     * @return void
     */ 
    private function log($message)
    {
        if ($this->getConfig('enable_log'))
        {
            $this->app['log']->info("Firewall: $message");
        }
    }

    /**
     * Register the Whitelist Artisan command
     *
     * @return void
     */ 
    private function registerWhitelistCommand()
    {
        $this->app['firewall.whitelist.command'] = $this->app->share(function($app)
        {
            return new WhitelistCommand;
        });
    }

    /**
     * Register the Blacklist Artisan command
     *
     * @return void
     */ 
    private function registerBlacklistCommand()
    {
        $this->app['firewall.blacklist.command'] = $this->app->share(function($app)
        {
            return new BlacklistCommand;
        });
    }

    /**
     * Register the List Artisan command
     *
     * @return void
     */ 
    private function registerReportCommand()
    {
        $this->app['firewall.list.command'] = $this->app->share(function($app)
        {
            return new ReportCommand;
        });
    }

    /**
     * Register the List Artisan command
     *
     * @return void
     */ 
    private function registerRemoveCommand()
    {
        $this->app['firewall.remove.command'] = $this->app->share(function($app)
        {
            return new RemoveCommand;
        });
    }

    /**
     * Register the List Artisan command
     *
     * @return void
     */ 
    private function registerClearCommand()
    {
        $this->app['firewall.clear.command'] = $this->app->share(function($app)
        {
            return new ClearCommand;
        });
    }

    /**
     * Get the root directory for this ServiceProvider
     * 
     * @return string
     */
    public function getRootDirectory()
    {
        return __DIR__.'/../..';
    }    
}