<?php
/*
Log.php - Logging class
Copyright (C) 2013  Bas Peters <bas@baspeters.com>

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
 * Logging class
 *
 * @category    Logging
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
 */
class Log {
    /**
     * Class constants
     */
    const MAX_LOGFILE_REVISIONS = 5;

    /**
     * Private properties
     */
    private static $_module = 'MAIN';
    private static $_stdOut = null;
    private static $_stdErr = null;
    private static $_logDoty = null;
    private static $_logClientPidCallback = null;

    /**
     * Sets the name of the current module that uses the log
     *
     * @param string $module Module name
     */
    public static function setModule($module) {
        self::$_module = $module;
    }

    /**
     * Logs an information line
     *
     * @param string $line The log line
     * @return string Formatted log line that was written to the log
     */
    public static function info($line) {
        if ( !is_resource(self::$_stdOut) ) {
            self::_checkFileDescriptors();
        }
        return self::_writeMessage($line, self::$_stdOut);
    }

    /**
     * Logs an error line
     *
     * @param string $line The log line
     * @return string Formatted log line as written to the log
     */
    public static function error($line) {
        if(!is_resource(self::$_stdErr)) {
            self::_checkFileDescriptors();
        }
        return self::_writeMessage($line, self::$_stdErr);
    }

    /**
     * Registers a callback function to retrieve process ids from processes that log into the log files
     *
     * @param callback $callback The callback function to retrieve the pid list
     * @throws Exception
     */
    public static function registerLogClientPidsCallback($callback) {
        if(!is_callable($callback)) {
            throw new Exception('Log client PID Callback function is not callable');
        } elseif(!is_null(self::$_logClientPidCallback)) {
            throw new Exception('Log client PID Callback function is already registered');
        }
        self::$_logClientPidCallback = $callback;
    }

    /**
     * Executed after the logs are rotated and will close and reopen the log file descriptors
     */
    public static function postRotate() {
        self::info('Recycling logfile file descriptors after rotate');
        fclose(self::$_stdOut);
        fclose(self::$_stdErr);
        self::_checkFileDescriptors();
    }

    /**
     * Method to rotate the logfiles at midnight local time
     */
    public static function rotate() {
        // check for the log root location
        if(!defined('LOG_ROOT')) {
            // if the log root path is not known, bail out
            return;
        }

        // if the day of the year was not previously set, define it now
        if(is_null(self::$_logDoty)) {
            self::$_logDoty = date('z');
        }

        // check if there was a date change
        if(date('z')==self::$_logDoty) {
            // no date change detected, the log rotate should not be executed
            return;
        } else {
            // update the date change
            self::$_logDoty = date('z');
        }

        self::info('Rotating the logfiles');

        // gather information about the current logfiles
        $allFiles = @scandir(LOG_ROOT, 1);
        if($allFiles===false) return;

        $logFiles = array();
        foreach($allFiles as $file) {
            preg_match('/^((?P<filename>(.*?)\.log(\.?(?P<revision>\d+))?))$/', $file, $matches);
            if(isset($matches['filename'])) {
                $logFiles[] = array(
                    'filename' => $matches['filename'],
                    'revision' => (isset($matches['revision']) ? $matches['revision'] : '')
                );
            }
        }

        // increase the revision numbers of each logfile
        foreach($logFiles as $logFile) {
            if($logFile['revision'] >= self::MAX_LOGFILE_REVISIONS) {
                @unlink(LOG_ROOT.$logFile['filename']);
            } elseif(($revision = $logFile['revision'])>0) {
                $newFilename = str_replace($revision, $revision+1, $logFile['filename']);
                @rename(LOG_ROOT.$logFile['filename'], LOG_ROOT.$newFilename);
            } elseif($logFile['revision']=='') {
                @rename(LOG_ROOT.$logFile['filename'], LOG_ROOT.$logFile['filename'].'.1');
            }
        }

        self::postRotate();

        // notify all log clients to reopen the file descriptors
        $logClientPids = array();
        if(!is_null(self::$_logClientPidCallback)) {
            $logClientPids = call_user_func(self::$_logClientPidCallback);
        }
        foreach($logClientPids as $logClientPid) {
            if($logClientPid>0) {
                posix_kill($logClientPid, SIGHUP);
            }
        }
    }

    /**
     * Checks if the logging file descriptors are correctly set
     */
    private static function _checkFileDescriptors() {
        try {
            if(class_exists('Daemonizer') && Daemonizer::$daemonized && is_resource(Daemonizer::$stdout) && is_resource(Daemonizer::$stderr)) {
                if (!is_resource(self::$_stdOut)) {
                    self::$_stdOut = Daemonizer::$stdout;
                }
                if (!is_resource(self::$_stdErr)) {
                    self::$_stdErr = Daemonizer::$stderr;
                }
            } elseif(class_exists('Daemonizer') && !Daemonizer::$daemonized) {
                if(!is_resource(self::$_stdOut)) {
                    self::$_stdOut = fopen('php://stdout', 'wb');
                }
                if(!is_resource(self::$_stdErr)) {
                    self::$_stdErr = fopen('php://stderr', 'wb');
                }
            } elseif(!is_null(Config::get('log_application')) && !is_null(Config::get('log_error'))) {
                if(!is_resource(self::$_stdOut)) {
                    self::$_stdOut = fopen(Config::get('log_application'), 'ab');
                }
                if(!is_resource(self::$_stdErr)) {
                    self::$_stdErr = fopen(Config::get('log_error'), 'ab');
                }
            } else {
                if(!is_resource(self::$_stdOut)) {
                    self::$_stdOut = fopen('/dev/null', 'wb');
                }
                if(!is_resource(self::$_stdErr)) {
                    self::$_stdErr = fopen('/dev/null', 'wb');
                }
            }
        }
        catch ( Exception $e ) {
            die( 'Error opening logging streams for STDOUT en STDERR: ' . $e->getMessage() );
        }
    }

    /**
     * Low level function that performs the actual log write
     *
     * @param string $line The line to be logged
     * @param resource $fd File descriptor where the logging needs to be written
     * @return string Formatted log line as written to the log
     */
    private static function _writeMessage($line, $fd) {
        $line = date('Y-m-d H:i:s')."  ".(is_null(self::$_module) ? '' : '['.self::$_module.'] ')."$line".PHP_EOL;
        fwrite($fd, $line);

        return $line;
    }
}
