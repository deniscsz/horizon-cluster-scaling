<?php

declare(strict_types=1);

namespace Deniscsz\HorizonClusterScaling;

use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\AutoScaler;

class HorizonClusterScalingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/horizon-cluster-scaling.php',
            'horizon-cluster-scaling'
        );

        $this->app->singleton(MasterCountResolver::class);

        // Wrap Horizon's AutoScaler with our cluster-aware decorator.
        // extend() ensures the original AutoScaler is constructed first
        // by Horizon's service provider with its own dependencies, then
        // we wrap it. Service provider load order does not matter.
        $this->app->extend(AutoScaler::class, function (AutoScaler $autoScaler, $app) {
            return new ClusterAwareAutoScaler(
                $autoScaler,
                $app->make(MasterCountResolver::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/horizon-cluster-scaling.php' => config_path('horizon-cluster-scaling.php'),
            ], 'horizon-cluster-scaling-config');
        }
    }
}
