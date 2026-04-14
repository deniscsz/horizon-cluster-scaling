<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Cluster-Aware Scaling
    |--------------------------------------------------------------------------
    |
    | When enabled, the package will dynamically adjust each supervisor's
    | maxProcesses by dividing it by the number of active Horizon master
    | supervisors detected in the cluster. Set to false to disable.
    |
    */

    'enabled' => env('HORIZON_CLUSTER_SCALING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Master Count Cache TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long to cache the active master supervisor count before querying
    | Redis again. A lower value means faster reaction to topology changes
    | but more Redis queries. Default 5s balances responsiveness and overhead.
    |
    */

    'cache_ttl' => (int) env('HORIZON_CLUSTER_SCALING_CACHE_TTL', 5),

    /*
    |--------------------------------------------------------------------------
    | Minimum Effective Max Processes
    |--------------------------------------------------------------------------
    |
    | An absolute floor for the effective maxProcesses per server, regardless
    | of division. Set to null to use each supervisor's own minProcesses as
    | the floor (recommended). Set to an integer to enforce a global minimum.
    |
    */

    'min_effective_max' => env('HORIZON_CLUSTER_SCALING_MIN_EFFECTIVE_MAX'),

];
