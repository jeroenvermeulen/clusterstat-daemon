<?php
/*
ProcStats.php - Class for gathering process statistics
Copyright (C) 2013  Jeroen Vermeulen <info@jeroenvermeulen.eu>

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
 * @category    Statistics
 * @package     ClusterStatsDaemon
 * @author      Jeroen Vermeulen <info@jeroenvermeulen.eu>
 *
 * Class ProcStats
 *
 * The daemon timer runs timerCollectStats every second.
 * The daemon timer runs timerWriteDatabase every 5 minutes, to backup data in case the daemon gets restarted.
 *
 * Functions to retrieve collected process statistics data:
 *   - getProcStats           - Returns an array, provides the data for the other data retrieving functions
 *   - getJsonProcStats       - Returns JSON string
 *   - getCactiProcStats      - Returns "user1:NUMBER user2:NUMBER" string for Cacti, jiffies
 *   - getCactiProcCount      - Returns "user1:NUMBER user2:NUMBER" string for Cacti, process count
 *   - getNagiosProcStats     - Returns "user1=NUMBER user2=NUMBER" string for Nagios
 *   - getProcStatsDetailHtml - Returns a HTML layout detailed overview
 *
 */
class ProcStats {

    protected $_procPath     = '/proc';
    protected $_prevStatData = array();
    protected $_database     = null;
    protected $_uidCache     = array();
    protected $_sqliteFile   = 'userstats.sqlite';
    protected $_statFifo     = array();
    protected $_statFifoLen  = 3;
    protected $_workDir      = null;
    protected $_dbData       = null; // is filled by _getFromDatabase and timerCollectStats

    /**
     * Constructor - initialize some data.
     *
     * @return ProcStats
     */
    public function __construct()
    {
        $this->_workDir = dirname( dirname(__FILE__) );
        $this->_dbData = $this->_getFromDatabase();
    }

    /**
     * Destructor - clean up stuff
     */
    public function __destruct()
    {
        $this->_database = null; // PDO will cleanup the connection
    }

    /**
     * Returns an array, provides the data for the other data retrieving functions
     *
     * @return array Process statistics.
     */
    public function getProcStats()
    {
        $result = array();
        if ($this->_statFifoLen <= count($this->_statFifo) )
        {
            $startStats = end( $this->_statFifo );
            $endStats = reset( $this->_statFifo );

            if ( is_array($startStats) && is_array($endStats) )
            {
                foreach ( $this->_dbData as $uid => &$nil )
                {
                    $user = $this->_uidToUser($uid);

                    $result[$user]['TOTAL']['jiff']    = 0;
                    $result[$user]['TOTAL']['counter'] = 0;
                    $result[$user]['TOTAL']['procs'] = 0;

                    if (  isset($startStats[$uid])
                       && is_array($startStats[$uid])
                       )
                    {
                        foreach ( $this->_dbData[$uid] as $process => &$nil2 )
                        {
                            $result[$user][$process]['jiff'] = 0;
                            $result[$user][$process]['procs'] = 0;
                            if (  !empty($startStats[$uid][$process]['jiff'])
                               && !empty($endStats[$uid][$process]['jiff'])
                               )
                            {
                                $timeDiff = $endStats[$uid][$process]['time'] - $startStats[$uid][$process]['time'];
                                $diffJiff = $endStats[$uid][$process]['jiff'] - $startStats[$uid][$process]['jiff'];
                                $diffJiff = round( $diffJiff / $timeDiff );
                                $diffJiff = max( 0, $diffJiff );
                                $result[$user][$process]['jiff']  = $diffJiff;
                                $result[$user]['TOTAL']['jiff']   += $diffJiff;
                            }
                            if ( !empty($endStats[$uid][$process]['procs']) )
                            {
                                $result[$user][$process]['procs']   = $endStats[$uid][$process]['procs'];
                                $result[$user]['TOTAL']['procs']    += $endStats[$uid][$process]['procs'];
                            }
                            $result[$user][$process]['counter'] = $this->_dbData[$uid][$process]['counter'];
                            $result[$user]['TOTAL']['counter']  += $this->_dbData[$uid][$process]['counter'];
                        }
                        unset( $nil2 );
                    }
                }
            }
            unset( $nil );
        }
        ksort( $result );
        return $result;
    }

    /**
     * Returns JSON encoded process statistics
     *
     * @return string - JSON encoded process statistics
     */
    public function getJsonProcStats()
    {
        //return $this->_jsonIndent( json_encode($result) );
        return json_encode($this->getProcStats());
    }

    /**
     * Returns "user1:NUMBER user2:NUMBER" string for Cacti, jiffies
     *
     * @return string - data about used jiffies per user for Cacti
     */
    public function getCactiProcStats()
    {
        $result = '';
        $stats = $this->getProcStats();
        if ( is_array($stats) )
        {
            $allTotal = 0;
            $users = array_keys($stats);
            foreach ( $users as $user )
            {
                $result .= sprintf( '%s:%d ', $user, $stats[$user]['TOTAL']['counter'] );
                $allTotal += $stats[$user]['TOTAL']['counter'];
            }
            $result .= sprintf( '%s:%d ', 'TOTAL', $allTotal );
            unset($allTotal);
            unset($users);
        }
        unset($stats);
        return $result;
    }

    /**
     * Returns "user1:NUMBER user2:NUMBER" string for Cacti, process count
     *
     * @return string - data about number of processes per user for Cacti
     */
    public function getCactiProcCount()
    {
        $result = '';
        $stats = $this->getProcStats();
        if ( is_array($stats) )
        {
            $allTotal = 0;
            $users = array_keys($stats);
            foreach ( $users as $user )
            {
                $result .= sprintf( '%s:%d ', $user, $stats[$user]['TOTAL']['procs'] );
                $allTotal += $stats[$user]['TOTAL']['procs'];
            }
            $result .= sprintf( '%s:%d ', 'TOTAL', $allTotal );
            unset($allTotal);
            unset($users);
        }
        unset($stats);
        return $result;
    }

    /**
     * Returns "user1=NUMBER user2=NUMBER" string for Nagios
     *
     * @return string, data about used jiffies per user for Nagios
     */
    public function getNagiosProcStats()
    {
        $result = '';
        $stats = $this->getProcStats();
        if ( is_array($stats) )
        {
            $users = array_keys($stats);
            $result .= 'OK | ';
            $allTotal = 0;
            foreach ( $users as $user )
            {
                if ( isset($stats[$user]['TOTAL']['counter']) )
                {
                    $value     = $stats[$user]['TOTAL']['counter'];
                    $result   .= sprintf( '%s=%dc ', $user, $value );
                    $allTotal += $value;
                    unset($value);
                }
            }
            $result .= sprintf( '%s=%dc ', 'TOTAL', $allTotal );
            unset($user);
            unset($allTotal);
            unset($users);
        }
        unset($stats);
        return $result;
    }

    /**
     * Returns CPU usage statistics data for Munin
     *
     * @param $requestInfo - Array with request info
     * @return string, data about used jiffies per user for Munin
     */
    public function getMuninProcStats( $requestInfo )
    {
        $result = '';
        $pathParts = explode( '/', $requestInfo['PATH'] );
        switch( $pathParts[3] ) {
            case 'jiffies':
                $key = 'counter';
                break;
            case 'procs':
                $key = 'procs';
                break;
            default:
                return sprintf('Unknown key: "%s"',$pathParts[3]);
        }
        $urlSplit = explode( '?', $requestInfo['URL'] );
        $baseUrl = reset( $urlSplit );
        $config = ( 'config' == $requestInfo['QUERY_STRING'] );

        $result .= sprintf( "# You can fetch the values from:  %s\n", $baseUrl );
        $result .= sprintf( "# You can fetch the config from:  %s?config\n", $baseUrl );
        $stats = $this->getProcStats();
        if ( is_array($stats) )
        {
            $users = array_keys($stats);
            sort( $users );
            $allTotal = 0;
            foreach ( $users as $user )
            {
                $fieldName = $user;
                if ( 'root' == $fieldName ) {
                    $fieldName = 'uroot';
                }
                if ( isset($stats[$user]['TOTAL']['counter'])
                     && $stats[$user]['TOTAL']['counter'] > 100 )
                {
                    if ( 'config' == $requestInfo['QUERY_STRING'] ) {
                        $result   .= sprintf( "%s.label %s\n", $fieldName, $user );
                        $result   .= sprintf( "%s.min 0\n", $fieldName );
                        $result   .= sprintf( "%s.draw LINE1\n", $fieldName );
                        if ( 'counter' == $key ) {
                            $result   .= sprintf( "%s.type DERIVE\n", $fieldName );
                            $result   .= sprintf( "%s.max 3200\n", $fieldName );
                        }
                    } else {
                        $result   .= sprintf( "%s.value %d\n", $fieldName, $stats[$user]['TOTAL'][$key] );
                        $allTotal += $stats[$user]['TOTAL'][$key];
                    }
                }
            }
            if ( $config ) {
                $result .= "TOTAL.label TOTAL\n";
                $result .= "TOTAL.min 0\n";
                $result .= "TOTAL.draw LINE1\n";
                $result .= "TOTAL.colour cccccc\n";
                if ( 'counter' == $key ) {
                    $result .= "TOTAL.type DERIVE\n";
                    $result .= "TOTAL.max 3200\n";
                }
            } else {
                $result .= sprintf( "%s.value %d\n", 'TOTAL', $allTotal );
            }
            if ( $config ) {
                if ( 'counter' == $key ) {
                    $result .= "graph_title CPU Usage\n";
                    $result .= "graph_vlabel jiffies\n";
                    $result .= "graph_info CPU usage per user. 100 jiffies = 1 full CPU core.\n";
                    $result .= "graph_args --upper-limit 800 --lower-limit 0 --rigid --slope-mode --units-exponent 1\n";
                } elseif ( 'procs' == $key ) {
                    $result .= "graph_title Running Processes\n";
                    $result .= "graph_vlabel processes\n";
                    $result .= "graph_info Running processes per user.\n";
                    $result .= "graph_args --lower-limit 0 --slope-mode --units-exponent 1\n";
                }
                $result .= "graph_category system\n";
                $result .= "graph_scale no\n";
            }
            unset($user);
            unset($allTotal);
            unset($users);
        }
        unset($urlSplit);
        unset($baseUrl);
        unset($stats);
        return $result;
    }

    /**
     * Returns a HTML layout detailed overview
     *
     * @return string - HTML for detailed overview
     */
    public function getProcStatsDetailHtml()
    {
        $result       = '';
        $result      .= '<table border="1" cellpadding="2" style="border-collapse:collapse; border-bottom:2px solid black;">'."\n";
        $result      .= '<tr>';
        $result      .= '<th>user</th>';
        $result      .= '<th colspan=3>current</th>';
        $result      .= '<th colspan=2>total</th>';
        $result      .= '</tr>'."\n";
        $result      .= '<tr>';
        $result      .= '<th>process</th>';
        $result      .= '<th colspan=1>procs</th>';
        $result      .= '<th colspan=2>jiff / sec</th>';
        $result      .= '<th colspan=2>counter</th>';
        $result      .= '</tr>'."\n";

        $stats        = $this->getProcStats();
        if ( is_array($stats) )
        {
            $counterTotal = 0;
            $jiffTotal    = 0;
            $procsTotal   = 0;
            uasort( $stats, array($this,'_userCmp') );
            $users = array_keys($stats);
            foreach ( $users as $user )
            {
                if (  isset($stats[$user]['TOTAL']['counter'])
                   && isset($stats[$user]['TOTAL']['jiff'])
                   )
                {
                    $counterTotal += $stats[$user]['TOTAL']['counter'];
                    $jiffTotal    += $stats[$user]['TOTAL']['jiff'];
                    $procsTotal   += $stats[$user]['TOTAL']['procs'];
                }
            }
            $stats['TOTAL']['TOTAL']['counter'] = $counterTotal;
            $stats['TOTAL']['TOTAL']['jiff']    = $jiffTotal;
            $stats['TOTAL']['TOTAL']['procs']   = $procsTotal;
            $counterTotal = empty($counterTotal) ? 1 : $counterTotal;
            $jiffTotal    = empty($jiffTotal)    ? 1 : $jiffTotal;
            array_unshift( $users, 'TOTAL' );
            foreach ( $users as $user )
            {
                $userStats    = $stats[$user];
                unset( $userStats['TOTAL'] );
                uasort( $userStats, array($this,'_userProcCmp') );
                $procs = array_keys( $userStats );
                array_unshift($procs, 'TOTAL');
                foreach ( $procs as $process )
                {
                    if (  isset($stats[$user][$process]['counter'])
                       && isset($stats[$user][$process]['jiff'])
                       )
                    {
                        $counter      = $stats[$user][$process]['counter'];
                        $counterPerc  = $counter * 100 / $counterTotal;
                        $jiff         = $stats[$user][$process]['jiff'];
                        $jiffPerc     = $jiff * 100 / $jiffTotal;
                        $procs        = $stats[$user][$process]['procs'];
                        $style        = '';
                        $name         = $process;
                        if ( 'TOTAL' == $user )
                        {
                            $style    = 'background-color:#74B4CA; border-top:2px solid black;';
                        }
                        elseif ( 'TOTAL' == $process )
                        {
                            $style    = 'background-color:#C8DAE6; border-top:2px solid black;';
                            $name     = $user;
                        }
                        $result      .= sprintf( '<tr style="%s">', $style);
                        $result      .= sprintf( '<td>%s</td>', $name );
                        $result      .= sprintf( '<td width="30" align="right">%d</td>', $procs );
                        if ( empty($jiff) )
                        {
                            $result      .= '<td width="30">&nbsp;</td>';
                            $result      .= '<td width="60">&nbsp;</td>';
                        }
                        else
                        {
                            $result      .= sprintf( '<td width="30" align="right">%d</td>', $jiff );
                            $result      .= sprintf( '<td width="60" align="right">%.02f%%</td>', $jiffPerc );
                        }
                        if ( empty($counter) )
                        {
                            $result      .= '<td>&nbsp;</td>';
                            $result      .= '<td>&nbsp;</td>';
                        }
                        else
                        {
                            $result      .= sprintf( '<td align="right">%d</td>', $counter );
                            $result      .= sprintf( '<td align="right">%.02f%%</td>', $counterPerc );
                        }
                        $result      .= '</tr>'."\n";
                        unset($counter);
                        unset($counterPerc);
                        unset($jiff);
                        unset($jiffPerc);
                        unset($style);
                        unset($name);
                    }
                }
                unset($process);
                unset($userStats);
            }
            $result .= "</table>\n";
            unset($users);
            unset($counterTotal);
            unset($jiffTotal);
        }
        unset($stats);
        return $result;
    }

    /**
     * timerCollectStats
     *
     * This function must be run every X seconds by the daemon.
     * It calls functions to collect data from /proc, do calculations and store it in the object in $this->_dbData
     */
    public function timerCollectStats()
    {
        $statData = $this->_collectProcStats();
        //echo $this->_debugTree( $statData ); // Useful for debugging, outputs to log

        $userProcStats = $this->_getUserProcStats( $statData, $this->_prevStatData );
        $this->_prevStatData = $statData;

        foreach ( $userProcStats as $uid => &$nil )
        {
            foreach ( $userProcStats[$uid] as $process => &$nil2 )
            {
                if (  isset( $this->_dbData[$uid][$process]['counter'] ) )
                {
                    // Delta mechanism takes place in _getUserProcStats.
                    // Previous data found in database
                    $this->_dbData[$uid][$process]['counter'] += $userProcStats[$uid][$process]['jiff'];
                    unset($delta);
                }
                else
                {
                    // No entry found in database, create initial data.
                    $this->_dbData[$uid][$process]['linuxuser'] = $uid;
                    $this->_dbData[$uid][$process]['process']   = $process;
                    $this->_dbData[$uid][$process]['counter']   = $userProcStats[$uid][$process]['jiff'];
                    $this->_dbData[$uid][$process]['procs']     = $userProcStats[$uid][$process]['procs'];
                }
                $this->_dbData[$uid][$process]['lastvalue'] = $userProcStats[$uid][$process]['jiff'] ;
                $userProcStats[$uid][$process]['counter']   = $this->_dbData[$uid][$process]['counter'];
            }
            unset($nil2);
            unset($process);
        }
        unset($nil);
        unset($user);

        if ($this->_statFifoLen <= count($this->_statFifo) )
        {
            array_pop($this->_statFifo);
        }
        array_unshift($this->_statFifo,$userProcStats);

        unset($userProcStats);
    }

    /**
     * Outputs a tree useful for debugging
     *
     * @return string - ascii art tree with collected process information
     */
    public function debugTree()
    {
        $statData = $this->_collectProcStats();
        return $this->_debugTree( $statData );
    }

    /**
     * To be called by timer, writes database to file so it doesn't get lost on daemon restart.
     */
    public function timerWriteDatabase()
    {
        $this->_writeToDatabase( $this->_dbData );
    }

    /**
     * Get an SQLite database connection
     *
     * @throws Exception
     * @return PDO
     */
    protected function _getDatabase()
    {
        $sqliteFile   = $this->_workDir . '/' . $this->_sqliteFile;
        $templateFile = $this->_workDir.'/templates/'.$this->_sqliteFile;
        if ( empty($this->_database) )
        {
            if ( !file_exists($sqliteFile) )
            {
                if ( file_exists($templateFile) )
                {
                    $copyOK = copy( $templateFile, $sqliteFile );
                    if ( !$copyOK )
                    {
                        throw new Exception('Copy of SQLite file from template "'.$templateFile.'" to "'.$sqliteFile.'" failed.');
                    }
                    unset($copyOK);
                }
                else
                {
                    throw new Exception('SQLite file does not exist, and template database file does also not exist.');
                }
            }
            // Create (connect to) SQLite database in file
            $this->_database = new PDO('sqlite:'.$sqliteFile);
            // Set errormode to exceptions
            $this->_database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        unset($sqliteFile);
        unset($templateFile);
        return $this->_database;
    }

    /**
     * Read process data to SQLite database that is used to detect and fixes
     * resets of counters.
     *
     * @return array - the row data
     */
    protected function _getFromDatabase()
    {
        $result = array();
        $rowSet = $this->_getDatabase()->query('SELECT linuxuser,process,lastvalue,counter FROM jiffy');
        if ( is_array($rowSet) )
        {
            foreach ($rowSet as $row)
            {
                $result[ $row['linuxuser'] ][ $row['process'] ] = $row;
            }
            unset($row);
        }
        unset($rowSet);
        return $result;
    }

    /**
     * Write process data to SQLite database that is used to detect and fixes
     * resets of counters.
     *
     * @param array $data - the row data to write
     */
    protected function _writeToDatabase( $data )
    {
        $database    = $this->_getDatabase();
        $database->beginTransaction();

        $updateSQL   = 'UPDATE jiffy SET lastvalue=:lastvalue, counter=:counter WHERE linuxuser=:linuxuser AND process=:process';
        $updateStmt  = $database->prepare($updateSQL);
        unset($updateSQL);
        $insertSQL   = 'INSERT INTO jiffy (linuxuser,process,lastvalue,counter) VALUES (:linuxuser,:process,:lastvalue,:counter)';
        $insertStmt  = $database->prepare($insertSQL);
        unset($insertSQL);

        $linuxuser   = '';
        $process     = '';
        $lastvalue   = '';
        $counter      = '';

        // Bind parameters to statement variables
        $updateStmt->bindParam( ':linuxuser',   $linuxuser );
        $updateStmt->bindParam( ':process',     $process );
        $updateStmt->bindParam( ':lastvalue',   $lastvalue );
        $updateStmt->bindParam( ':counter',     $counter );

        $insertStmt->bindParam( ':linuxuser',   $linuxuser );
        $insertStmt->bindParam( ':process',     $process );
        $insertStmt->bindParam( ':lastvalue',   $lastvalue );
        $insertStmt->bindParam( ':counter',     $counter );

        // Loop through all messages and execute prepared insert statement
        if ( is_array($data) )
        {
            foreach ($data as $userdata)
            {
                if ( is_array($userdata) )
                {
                    foreach ($userdata as $row)
                    {
                        if (  isset($row['linuxuser'])
                           && isset($row['process'])
                           && isset($row['lastvalue'])
                           && isset($row['counter'])
                           )
                        {
                            // Set values to bound variables
                            $linuxuser   = $row['linuxuser'];
                            $process     = $row['process'];
                            $lastvalue   = $row['lastvalue'];
                            $counter     = $row['counter'];

                            // Execute statement
                            $updateStmt->execute();
                            if ( 0 == $updateStmt->rowCount() )
                            {
                                $insertStmt->execute();
                                if ( 0 == $insertStmt->rowCount() )
                                {
                                    Log::error(__CLASS__.'->'.__FUNCTION__.': Insert of record into SQLite database failed');
                                }
                            }
                        }
                    }
                    unset($row);
                }
            }
            unset($userdata);
        }
        unset($data);

        $database->commit();

        unset($updateStmt);
        unset($insertStmt);
        unset($database);
        unset($linuxuser);
        unset($process);
        unset($lastvalue);
        unset($counter);
    }

    /**
     * Loop trough all dirs in /proc and collect process data.
     * This means data is collected for all current running processes.
     *
     * @throws Exception
     * @return Array $result - in the form of:
     *                        $result[ PID ][ 'pid' ]           = Integer
     *                        $result[ PID ][ 'name' ]          = String
     *                        $result[ PID ][ 'parentPid' ]     = Integer
     *                        $result[ PID ][ 'uid' ]           = Integer
     *                        $result[ PID ][ 'thisJiff' ]      = Integer
     *                        $result[ PID ][ 'deadChildJiff' ] = Integer
     *                        $result[ PID ][ 'time' ]          = Integer
     *                        $result[ PID ][ 'childPids' ]     = Array of Integer
     */
    protected function _collectProcStats()
    {
        $result = array();
        $dirHandle = opendir($this->_procPath);
        if ( false === $dirHandle )
        {
            throw new Exception('Could not open proc filesystem in "'.$this->_procPath.'".');
        }
        else
        {
            while (false !== ($entry = readdir($dirHandle)))
            {
                if ( is_numeric($entry) ) // $entry = PID
                {
                    $this->_getProcInfo( $entry, $result );
                }
            }
            unset($entry);
            closedir( $dirHandle );
        }
        unset($dirHandle);
        return $result;
    }

    /**
     * Loops trough the data in $statData and creates a sum for each process name under each user.
     *
     * @param  $statData      array received from _collectProcStats
     * @param  $prevStatData  array received from previous run of _collectProcStats
     * @return array - Array with sums like:  $result[ USER ][ PROCESSNAME ][ 'jiff' ]  = sum of used jiffies since last collect
     *                                        $result[ USER ][ PROCESSNAME ][ 'time' ]  = unix time of getting info from /proc
     *                                        $result[ USER ][ PROCESSNAME ][ 'procs' ] = number of processes
     */
    protected function _getUserProcStats( $statData, $prevStatData )
    {
        $result = array();

        foreach ( $statData as $pid => $aProcInfo )
        {
            $uid      = $aProcInfo['uid'];
            $procName = $aProcInfo['name'];

            if ( !isset( $result[$uid][$procName] ) )
            {
                $result[$uid][$procName]['procs'] = 0;
                $result[$uid][$procName]['jiff'] = 0;
            }

            $result[$uid][$procName]['procs']++;
            $iJiff = $aProcInfo['thisJiff'];
            if ( isset( $prevStatData[$pid]['thisJiff'] ) )
            {
                // Process was already running previous collect time
                $iJiff = $iJiff - $prevStatData[$pid]['thisJiff'];
                $iJiff = max( 0, $iJiff ); // make sure it does not get negative
            }
            $result[$uid][$procName]['jiff'] += $iJiff;
            $result[$uid][$procName]['time'] = $aProcInfo['time'];

            unset($uid);
            unset($procName);
            unset($iJiff);
        }
        unset($aProcInfo);

        return $result;
    }

    /**
     * Get the details from /proc for one specific process ID.
     * The data gets stored in $result.
     *
     * @param $pid integer - Process ID to get the data for.
     * @param $result array - Reference to array to store results in. See comments on _collectProcStats function.
     */
    protected function _getProcInfo( $pid, &$result )
    {
        $procStatFile =   $this->_procPath.'/'.$pid.'/stat';
        $procStatusFile = $this->_procPath.'/'.$pid.'/status';

        try
        {
            $uid          = null;
            $fh           = fopen($procStatusFile, 'r');
            $statusData   = fread($fh, 4096);
            fclose($fh);
            unset($fh);

            $match = array();
            if ( preg_match( '/Uid:\s*\d+\s*(\d+)/', $statusData, $match ) )
            {
                // Info about the '/proc/PID/status' fields:
                // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
                // search for "/proc/[pid]/status"
                $uid = $match[1];
            }
            if ( null === $uid )
            {
                $uid  = fileowner($procStatFile);
            }
            unset($match);

            $fMicroTime = microtime(true);

            $fh = fopen($procStatFile, 'r');
            $statLine = fread($fh, 1024);
            fclose($fh);
            unset($fh);

            $statFields   = explode( ' ', $statLine );

            if ( 17 <= count($statFields) )
            {
                // For info about the '/proc/PID/stat' fields:
                // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
                // search for "/proc/[pid]/stat"

                $process      = $statFields[1];
                $process      = trim( $process, ':()-0123456789/' );
                $process      = preg_replace('/\/\d+$/','',$process);
                $parentPid = $statFields[3];

                if ( !isset( $result[$pid]['childPids'] ) )
                {
                    $result[$pid]['childPids'] = array();
                }
                $result[$pid]['pid']       = $pid;
                $result[$pid]['name']      = $process;
                $result[$pid]['parentPid'] = $parentPid;
                if ( !empty($parentPid) ) {
                    $result[$parentPid]['childPids'][] = $pid;
                }

                $result[$pid]['uid']  = $uid;

                $result[$pid]['thisJiff'] = 0;
                $result[$pid]['thisJiff'] += $statFields[13]; // utime = user mode
                $result[$pid]['thisJiff'] += $statFields[14]; // stime = kernel mode

                $result[$pid]['deadChildJiff'] = 0;
                $result[$pid]['deadChildJiff'] += $statFields[15]; // cutime = ended children user mode
                $result[$pid]['deadChildJiff'] += $statFields[16]; // cstime = ended children kernel mode

                $result[$pid]['time'] = $fMicroTime;

                unset($statFields);
                unset($fMicroTime);
                unset($uid);
                unset($process);
                unset($parentPid);
                unset($pid);
            }
        }
        catch ( Exception $e )
        {
            // For performance reasons we do not check if the files we read do
            // exist. Then this exception is hit.
        }

        unset($procStatFile);
        unset($procStatusFile);
    }

    /**
     * Get username for a user id.
     *
     * @param int $uid - User ID to get username for
     * @return string  - Username
     */
    protected function _uidToUser( $uid )
    {
        if ( !isset($this->_uidCache[$uid]) )
        {
            $aUserInfo = posix_getpwuid($uid);
            if ( empty($aUserInfo['name']) )
            {
                $this->_uidCache[$uid] =  $uid;
            }
            else
            {
                $this->_uidCache[$uid] = $aUserInfo['name'];
            }
        }
        return $this->_uidCache[$uid];
    }

    /**
     * Recursive function to print ascii art tree with process info, for debugging.
     *
     * @param array $statData - Array received from _collectProcStats
     * @param int $pid        - Process Id to print info for
     * @param int $level      - Nesting level
     * @return string - ascii art tree with collected process info
     */
    protected function _debugTree( $statData, $pid=1, $level=0 )
    {
        $result = '';
        $result .= str_repeat(' ',$level*8);
        $result .= $pid . ' - '.$statData[$pid]['name'];
        $result .= ' - '.$this->_uidToUser($statData[$pid]['uid']);
        $result .= ' | ';
        $result .= $statData[$pid]['thisJiff'];
        $result .= ' / ';
        $result .= $statData[$pid]['deadChildJiff'];
        $result .= "\n";
        foreach ( $statData[$pid]['childPids'] as $childPid )
        {
            $result .= $this->_debugTree( $statData, $childPid, $level+1 );
        }
        return $result;
    }

    /**
     * Indent a JSON string to be more readable for debugging
     *
     * @param string $json
     * @return string
     */
    protected function _jsonIndent($json)
    {
        $result      = '';
        $pos         = 0;
        $strLen      = strlen($json);
        $indentStr   = '  ';
        $newLine     = "\n";
        $prevChar    = '';
        $outOfQuotes = true;

        for ($i=0; $i<=$strLen; $i++) {

            // Grab the next character in the string.
            $char = substr($json, $i, 1);

            // Are we inside a quoted string?
            if ($char == '"' && $prevChar != '\\') {
                $outOfQuotes = !$outOfQuotes;

                // If this character is the end of an element,
                // output a new line and indent the next line.
            } else if(($char == '}' || $char == ']') && $outOfQuotes) {
                $result .= $newLine;
                $pos --;
                for ($j=0; $j<$pos; $j++) {
                    $result .= $indentStr;
                }
            }

            // Add the character to the result string.
            $result .= $char;

            // If the last character was the beginning of an element,
            // output a new line and indent the next line.
            if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
                $result .= $newLine;
                if ($char == '{' || $char == '[') {
                    $pos ++;
                }

                for ($j = 0; $j < $pos; $j++) {
                    $result .= $indentStr;
                }
            }

            $prevChar = $char;
        }

        return $result;
    }

    /**
     * Function to compare the total counters of two users
     *
     * @param array $a
     * @param array $b
     * @return integer
     */
    protected function _userCmp($a, $b)
    {
        return $b['TOTAL']['counter'] - $a['TOTAL']['counter'];
    }

    /**
     * Function to compare the counters of two processes
     *
     * @param array $a
     * @param array $b
     * @return integer
     */
    protected function _userProcCmp($a, $b)
    {
        return $b['counter'] - $a['counter'];
    }

}
