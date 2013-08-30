<?php
/**
 * Main bootstrap for this application
 *
 * @category    Configuration
 * @package     ClusterStatisticsDaemon
 * @author      Bas Peters <bas.peters@nedstars.nl>
 */
date_default_timezone_set('Europe/Amsterdam');

// install exception error handler
function __errorHandler($errorNumber, $errorString, $errorFile, $errorLine) {
    if (!(error_reporting() & $errorNumber)) return;
    throw new Exception($errorString.' ('.basename($errorFile).":{$errorLine})");
    return true;
}

set_error_handler('__errorHandler');
ini_set('display_errors', 0);

// define application wide constants
define('APP_ROOT', realpath(dirname(__FILE__).DIRECTORY_SEPARATOR.'..').DIRECTORY_SEPARATOR);
define('LOG_ROOT', realpath(APP_ROOT.'logs').DIRECTORY_SEPARATOR);

// install autoloader
function __autoloadHandler($className) {
    $file = str_replace('_', '/', $className) . '.php';
    require_once( APP_ROOT . 'classes/' . $file );
}

spl_autoload_register('__autoloadHandler');

// install signal handler
function __signalHandler($signal) {
    switch($signal) {
        case SIGTERM:
        case SIGINT:
            Log::info('Shutdown request received. Shutting down');
            exit;
        case SIGHUP:
            Log::info('Reloading configuration');
            include APP_ROOT . 'includes/config.php';
            break;
        default:
            Log::info('Caught unhandled signal');
            break;
    }
}

pcntl_signal(SIGTERM, "__signalHandler");
pcntl_signal(SIGINT, "__signalHandler");
pcntl_signal(SIGHUP, "__signalHandler");
pcntl_signal(SIGUSR1, "__signalHandler");
pcntl_signal(SIGUSR2, "__signalHandler");

// load configuration
if ( file_exists( APP_ROOT . 'includes/config.php' ) ) {
    require_once( APP_ROOT . 'includes/config.php' );
} elseif ( file_exists( '/etc/clusterstat-daemon/config.php' ) ) {
    require_once( '/etc/clusterstat-daemon/config.php' );
} else {
    throw new Exception( 'Configuration file not found' );
}

// sanity checks
if(!is_writable(LOG_ROOT)) {
    throw new Exception('Log directory is not writable');
} elseif((file_exists(Config::get('log_application')) && !is_writable(Config::get('log_application'))) || (file_exists(Config::get('log_error')) && !is_writable(Config::get('log_error')))) {
    throw new Exception('Logfiles are not writable');
}

?>
