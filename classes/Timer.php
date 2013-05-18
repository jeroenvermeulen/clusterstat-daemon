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
                try {
                    call_user_func($timer['callback']);
                } catch(Exception $e) {
                    Log::error( "Error running timer function {$timer['callback']}: ".$e->getMessage() );
                    Log::info( "Timer function {$timer['callback']} triggered an error" );
                    return;
                }
                // Also update last_run on error so the timer doesn't keep firing when errors happen.
                $timer['last_run']=microtime(true);
            }
        }
    }
}