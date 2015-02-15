<?php
/*
Daemonizer.php - Utility class for daemonizing CLI processes
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
 * Utility class for daemonizing CLI processes
 *
 * @category    CLI
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
 */
class Daemonizer {
    /**
     * Public static properties
     */
    public static $pid = null;
    public static $daemonized = false;
    public static $stdout;
    public static $stderr;
    public static $stdin;

    /**
     * Private static properties
     */
    private static $_pidFile;
    private static $_scriptName;
    private static $_scriptArguments;
    private static $_runningPid = false;
    private static $_shouldStop = false;
    private static $_shouldStart = false;
    private static $_shouldReload = false;
    private static $_shouldRestart = false;
    private static $_shouldDebug = false;
    private static $_shouldUpstart = false;

    /**
     * Activate the daemonization process
     * @param string $processName Functional process name for administrational purposes
     */
    public static function daemonize($processName = null) {
        // sanity checks and initialization of required properties
        self::_sanityCheck();
        self::_initialize($processName);

        // check for correct commandline arguments
        if((int)self::$_shouldStop
           +(int)self::$_shouldStart
           +(int)self::$_shouldReload
           +(int)self::$_shouldRestart
           +(int)self::$_shouldDebug
           +(int)self::$_shouldUpstart
           !=1)
        {
            die('Invalid option '.implode(' ',self::$_scriptArguments)."\r\nusage: ".self::$_scriptName." <start|stop|reload|restart|debug>\r\n\r\n");
        }

        // process the requested arguments
        if(self::_isAlreadyRunning()) {
            if(self::$_runningPid===false) {
                die("PID file is locked. Is another instance still running?\r\n\r\n");
            } elseif(self::$_shouldReload) {
                posix_kill(self::$_runningPid, SIGHUP);
                die("Reloading configuration\r\n\r\n");
            } elseif(self::$_shouldStart || self::$_shouldDebug) {
                die('Another instance is already running under PID '.self::$_runningPid."\r\n\r\n");
            } elseif(self::$_shouldStop || self::$_shouldRestart) {
                echo 'Stopping current instance...';
                if(self::_stopRunningProcess()) {
                    echo "[OK]\r\n";
                } else {
                    die('[FAILED]\r\nCannot kill running instance under PID '.self::$_runningPid."\r\n\r\n");
                }
                if(self::$_shouldStop) {
                    die("Shutdown complete\r\n\r\n");
                }
            }
        } elseif(self::$_shouldStop) {
            die("No running instance found to be stopped\r\n\r\n");
        }

        // if the debug flag is set, prevent actual daemonization
        if(self::$_shouldDebug || self::$_shouldUpstart) return;

        // fork the current process, make session leader and kill the old parent process to daemonize
        if (($pid=pcntl_fork())==-1 || ($pid==0 && posix_setsid()<0)) {
            die("Error starting the new instance\r\n\r\n");
        } elseif ($pid!=0) {
            exit(0);
        }

        // administrative tasks to ensure that typical daemon requirements are met
        umask(0);
        if(defined('APP_ROOT')) chdir(APP_ROOT);

        self::$pid = getmypid();
        if(self::_writePidfile(self::$_pidFile, (int)self::$pid)===false) {
            die("Could not open PID file for writing\r\n\r\n");
        }
        echo 'Daemon started with PID '.self::$pid."\r\n\r\n";
        self::_redirectOutput();
        self::$daemonized = true;
        set_time_limit(0);
        usleep(100);
        // make sure that a cleanup is performed after the main script finishes
        register_shutdown_function('Daemonizer::cleanup');
    }

    /**
     * Cleanup method to be called when the main script finishes executing
     */
    public static function cleanup() {
        // check if we are the session leader, children must not execute this code
        if(posix_getsid(0)!=posix_getpid()) return;

        //remove pid file if it exists
        if(file_exists(self::$_pidFile)) @unlink(self::$_pidFile);

        Log::info('Shutdown complete');
    }

    /**
     * Checks if all prerequisites are met to use this class
     */
    private static function _sanityCheck() {
        if(substr(PHP_OS,0,3) == 'WIN') die("This application is only compatible with UNIX operating systems\r\n\r\n");
        if(PHP_SAPI!=='cli') die("This application needs to be run from the commandline\r\n\r\n");
        if(!function_exists('pcntl_fork')) die("PCNTL PHP extension is required to run this application\r\n\r\n");
        if(!function_exists('posix_kill')) die("POSIX PHP extension is required to run this application\r\n\r\n");
    }

    /**
     * Initialization of required private properties
     * @param string $processName Functional process name for administrational purposes
     */
    private static function _initialize($processName) {
        // commandline arguments are only available in the main scope
        // get them in scope here
        global $argv;

        if(isset($argv)) {
            self::$_scriptName = basename(array_shift($argv));
            self::$_scriptArguments = array_map('strtolower',$argv);
            self::$_shouldStop = in_array('stop', self::$_scriptArguments);
            self::$_shouldStart = in_array('start', self::$_scriptArguments);
            self::$_shouldReload = in_array('reload', self::$_scriptArguments);
            self::$_shouldRestart = in_array('restart', self::$_scriptArguments);
            self::$_shouldDebug = in_array('debug', self::$_scriptArguments);
        } else {
            self::$_scriptName = $processName;
            self::$_scriptArguments = array();
        }
        self::$_shouldUpstart = !empty($_SERVER['UPSTART_JOB']);
        if(@is_writable('/tmp/')) {
            $tmpDir = '/tmp/';
        } elseif(function_exists('sys_get_temp_dir')) {
            $tmpDir = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR;
        } else {
            die("Cannot write to temporary directory\r\n\r\n");
        }
        $_pidFile = is_null($processName) ? array_shift(@explode('.',self::$_scriptName)) : $processName;
        self::$_pidFile = "{$tmpDir}{$_pidFile}.pid";
    }

    /**
     * Redirect the standard input, output and error according to daemonizing rules
     */
    private static function _redirectOutput() {
        // if logfile configuration is not set, log to /dev/null
        if(is_null(Config::get('log_application'))) Config::set('log_application', '/dev/null');
        if(is_null(Config::get('log_error'))) Config::set('log_error', '/dev/null');

        // close and reopen the standard descriptors to redirect them
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        self::$stdin = fopen('/dev/null', 'r');
        self::$stdout = fopen(Config::get('log_application'), 'ab');
        self::$stderr = fopen(Config::get('log_error'), 'ab');
    }

    /**
     * Check to see if a previous instance is already running
     * @return bool Previous instance found
     */
    private static function _isAlreadyRunning() {
        if(file_exists(self::$_pidFile)) {
            self::$_runningPid=file_get_contents(self::$_pidFile);
            return(self::$_runningPid===false || posix_kill(self::$_runningPid,0));
        } else {
            return false;
        }
    }

    /**
     * Write the PID file to a temporary directory
     *
     * @param String $_pidFile - Location of the process id file
     * @param String $pid - Process Id
     *
     * @return bool PID file was successfully written
     */
    private static function _writePidfile($_pidFile, $pid) {
        return file_put_contents($_pidFile, $pid);
    }

    /**
     * Stop the currently running instance of the process
     * @return bool Current instance was succesfully killed
     */
    private static function _stopRunningProcess() {
        // if no running instance was found, return immediately
        if(self::$_runningPid===false || self::$_runningPid<1) return true;
        $epoch = time();

        // send a term signal to the running process
        posix_kill(self::$_runningPid, SIGTERM);
        while (posix_kill(self::$_runningPid,0)) {
            // monitor if the running process is going to die in a reasonable time
            usleep(50000);
            if($epoch+5<time()) {
                // reasonable time exceeded. Now kill the process the hard way
                posix_kill(self::$_runningPid, SIGKILL);
                if($epoch+7<time()) {
                    // the process refused to die
                    return false;
                }
            }
        }
        return true;
    }

}