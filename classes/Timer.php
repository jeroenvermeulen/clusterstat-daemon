<?php
/**
 * Timed events facilitation
 *
 * @category    Utility
 * @package     ClusterStatisticsDaemon
 * @author      Bas Peters <bas.peters@nedstars.nl>
 */
class Timer {
    
    /**
     * @var Array containing timers
     */
    private static $_timers = array();
    
    /**
     * Register a new timer
     * @param callback $callback The function to be called
     * @param float $timeout Timeout interval in seconds
     * @param bool $startExpired Should the last timeout be expired when registering
     * @return void
     */
    public static function register($callback, $timeout, $startExpired = false) {
        if(!is_callable($callback)) {
            throw new Exception('Callback function is not callable for this timer');
        }
        self::$_timers[] = array(
            'callback' => $callback,
            'timeout' => $timeout,
            'last_run' => ($startExpired ? 0.00 : microtime(true))
        );
    }
    /**
     * Checks the timers and fires the callback when a timeout has been reached
     *
     * @return void;
     */
    public static function checkTimers() {
        foreach(self::$_timers as &$timer) {
            if($timer['last_run']+$timer['timeout']<microtime(true)) {
                call_user_func($timer['callback']);
                $timer['last_run']=microtime(true);
            }
        }
    }
}