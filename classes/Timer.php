<?php
/*
Timer.php - Timed events facilitation
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
 * Timed events facilitation
 *
 * @category    Utility
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
 * @author      Jeroen Vermeulen <info@jeroenvermeulen.eu>
 */
class Timer {

    /**
     * @var Array containing timers
     */
    private static $_timers = array();

    /**
     * Register a new timer
     *
     * @param callback $callback The function to be called
     * @param float $timeout Timeout interval in seconds
     * @param bool $startExpired Should the last timeout be expired when registering
     * @throws Exception
     *
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