#!/usr/bin/env php
<?php
/*
clusterstatd.php - Main executable for the ClusterStat Daemon
Copyright (C) 2013  Bas Peters <bas@baspeters.com> & Jeroen Vermeulen <info@jeroenvermeulen.eu>

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
/**
 * @package  ClusterStatsDaemon
 * @author   Bas Peters       <bas@baspeters.com>
 * @author   Jeroen Vermeulen <info@jeroenvermeulen.eu>
 * @license  GNU General Public License, version 3
 *
 * Requires:
 *   CLI PHP >= 5.3.0
 *   PHP Extensions: PCNTL, POSIX, SQLite3
 */
try {
    // prepare environment
    require 'includes/bootstrap.php';

    // daemonize the script
    Daemonizer::daemonize();
    Log::info('Cluster statistics daemon started');

    // initialize webserver
    $webserver = new Webserver();
    $webserver->setCredentials(Config::get('http_username'), Config::get('http_password'));
    $webserver->setRoot(APP_ROOT . 'web');

    // initialize cluster statistics collection
    $stats = new ClusterStats();
    $procstats = new ProcStats();

    $stats->setProcStats( $procstats );

    $webserver->registerController( '/', array($stats, 'homepage'));
    $webserver->registerController( '/runtimestats.js', array($stats, 'getRuntimeStats'));
    $webserver->registerController( '/procstats_json', array($procstats, 'getJsonProcStats'), 'application/json' );
    $webserver->registerController( '/procstats_nagios', array($procstats, 'getNagiosProcStats'), 'text/plain' );
    $webserver->registerController( array( '/procstats/munin/jiffies/', '/procstats/munin/procs/' )
                                  , array( $procstats, 'getMuninProcStats' )
                                  , 'text/plain' );
    $webserver->registerController( '/procstats/munin/io/',    array($procstats, 'getMuninIoStats'),        'text/plain' );
    $webserver->registerController( '/procstats_detail_html',  array($procstats, 'getProcStatsDetailHtml'), 'text/html' );
    $webserver->registerController( '/procstats_debugcollect', array($procstats, 'debugCollect'),           'text/plain' );

    // register timers
    $timer = new Timer();
    $timer->register(array('Log','rotate'), 300);

    $timer->register(array($procstats,'timerCollectStats'), 1, true);
    $timer->register(array($procstats,'timerWriteDatabase'), 300, false);

    // enter main application loop
    while( true ) {
        $webserver->handleClients(250000);

        // dispatch pending timer
        $timer->checkTimers();

        // check for system signals
        pcntl_signal_dispatch();
    }
} catch(Exception $e) {
    Log::error('Fatal '.get_class($e).': '.$e->getMessage());
    exit($e->getCode());
}
