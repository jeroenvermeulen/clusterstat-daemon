<?php
/**
 * Main configuration file
 *
 * @category    Config
 * @package     ClusterStatisticsDaemon
 * @author      Bas Peters <bas.peters@nedstars.nl>
 */

// location of log files
Config::set('log_application',     LOG_ROOT . 'application.log');
Config::set('log_error',           LOG_ROOT . 'error.log');

// Webserver settings
Config::set('http_port',           8888);
Config::set('http_username',       'stats');
Config::set('http_password',       '7DxZpcJ9QqDDRr');

Config::set('http_ssl_enabled',    false);
Config::set('http_ssl_passphrase', 'zorgelozeitoplossingen');
