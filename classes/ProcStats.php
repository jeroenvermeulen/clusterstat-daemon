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
 * The daemon timer runs  timerCollectStats   every second. Fills _dbData + _statFifo
 * The daemon timer runs  timerWriteDatabase  every 5 minutes, to backup data in case the daemon gets restarted.
 *
 * Functions to retrieve collected process statistics data:
 *   - getProcStats           - Returns an array, provides the data for the other data retrieving functions
 *   - getJsonProcStats       - Returns JSON string
 *   - getNagiosProcStats     - Returns "user1=NUMBER user2=NUMBER" string for Nagios
 *   - getMuninProcStats
 *   - getMuninIoStats
 *   - getProcStatsDetailHtml - Returns a HTML layout detailed overview
 *
 * URLs:
 *   /procstats_json
 *   /procstats_nagios
 *   /procstats_detail_html
 *   /procstats/munin/jiffies/
 *   /procstats/munin/procs/
 *   /procstats/munin/io/
 *   /procstats_detail_html
 *   /procstats_debugtree
 *   
 */
class ProcStats {

    protected $_procPath     = '/proc';
    protected $_prevStatData = array();
    protected $_database     = null;
    protected $_uidCache     = array();
    protected $_sqliteFile   = 'userstats.sqlite';
    protected $_statFifo     = array(); // Is filled by timerCollectStats
    protected $_statFifoLen  = 3;
    protected $_workDir      = null;
    protected $_dbData       = null;    // Is filled by _getFromDatabase and timerCollectStats
    protected $_maxJiffies   = 25600;   // Let's say our server has max 256 cpu threads;
    protected $_counters     = array( 'jiffies', 'ioread', 'iowrite' );
    protected $_sortField    = 'jiffies_counter';
    protected $_sortOptions  = array('jiffies'=>'jiffies_counter', 'procs'=>'procs',
                                     'ioread'=>'ioread_counter', 'iowrite'=>'iowrite_counter');

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
     * @return array Process statistics in the form of:
     *                     $result[ USER ]['TOTAL']['jiffies']
     *                     $result[ USER ]['TOTAL']['jiffies_counter']
     *                     $result[ USER ]['TOTAL']['procs']
     *                     $result[ USER ][ PROCESS ]['jiffies']
     *                     $result[ USER ][ PROCESS ]['jiffies_counter']
     *                     $result[ USER ][ PROCESS ]['procs']
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
                foreach ( $this->_dbData as $uid => &$nil ) // for each user
                {
                    $user = $this->_uidToUser($uid);

                    $result[$user]['TOTAL']['procs']           = 0;
                    foreach ( $this->_counters as $counterName ) {
                        $counterKey = $counterName.'_counter';
                        $result[$user]['TOTAL'][$counterName] = 0;
                        $result[$user]['TOTAL'][$counterKey]  = 0;
                    }

                    if (  isset($startStats[$uid]) && is_array($startStats[$uid]) ) {
                        foreach ( $this->_dbData[$uid] as $process => &$nil2 ) { // for each process of this user
                            $result[$user][$process]['procs']   = 0;
                            if ( !empty($endStats[$uid][$process]['procs']) ) {
                                $result[$user][$process]['procs']   = $endStats[$uid][$process]['procs'];
                                $result[$user]['TOTAL']['procs']    += $endStats[$uid][$process]['procs'];
                            }
                            foreach ( $this->_counters as $counterName ) {
                                $counterKey = $counterName.'_counter';
                                $result[$user][$process][$counterName] = 0;
                                if (!empty($startStats[$uid][$process][$counterName])
                                    && !empty($endStats[$uid][$process][$counterName])
                                ) {
                                    $timeDiff = $endStats[$uid][$process]['time'] - $startStats[$uid][$process]['time'];
                                    $valJiff  = $endStats[$uid][$process][$counterName] - $startStats[$uid][$process][$counterName];
                                    $valJiff  = round($valJiff / $timeDiff);
                                    $valJiff  = max(0, $valJiff);
                                    $result[$user][$process][$counterName] = $valJiff;
                                    $result[$user]['TOTAL'][$counterName] += $valJiff;
                                }
                                $result[$user][$process][$counterKey] = $this->_dbData[$uid][$process][$counterKey];
                                $result[$user]['TOTAL'][$counterKey] += $this->_dbData[$uid][$process][$counterKey];
                                $result[$user]['TOTAL'][$counterKey] = $result[$user]['TOTAL'][$counterKey];
                            }
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
                $result .= sprintf( '%s:%d ', $user, $stats[$user]['TOTAL']['jiffies_counter'] );
                $allTotal += $stats[$user]['TOTAL']['jiffies_counter'];
            }
            $allTotal = $this->_wrapFix( $allTotal );
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
            $allTotal = $this->_wrapFix( $allTotal );
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
                if ( isset($stats[$user]['TOTAL']['jiffies_counter']) )
                {
                    $value     = $stats[$user]['TOTAL']['jiffies_counter'];
                    $result   .= sprintf( '%s=%dc ', $user, $value );
                    $allTotal += $value;
                    unset($value);
                }
            }
            $allTotal = $this->_wrapFix( $allTotal );
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
        if (isset($this->_sortOptions[$pathParts[3]])) {
            $key = $this->_sortOptions[$pathParts[3]];
        } else {
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
            if ( $config ) {
                $result .= "graph_category system\n";
                $result .= "graph_scale no\n";
                $result .= "TOTAL.label TOTAL\n";
                $result .= "TOTAL.draw LINE1\n";
                $result .= "TOTAL.colour eeeeee\n";
                if ('jiffies_counter' == $key) {
                    // CPU usage in Jiffies
                    $result .= "TOTAL.type DERIVE\n";
                    $result .= "TOTAL.min 0\n";
                    $result .= sprintf("TOTAL.max %d\n", $this->_maxJiffies);
                    $result .= "graph_title MH CPU Usage per User\n";
                    $result .= "graph_vlabel jiffies\n";
                    $result .= "graph_info CPU usage per user. 100 jiffies = 1 full CPU core.\n";
                    $result .= "graph_args --upper-limit 1200 --lower-limit 0 --rigid --slope-mode --units-exponent 1\n";
                } elseif ('procs' == $key) {
                    // Number of running processes
                    $result .= "TOTAL.min 0\n";
                    $result .= "TOTAL.max 245760\n"; // cat /proc/sys/kernel/pid_max
                    $result .= "graph_title MH Running Processes per User\n";
                    $result .= "graph_vlabel processes\n";
                    $result .= "graph_info Running processes per user.\n";
                    $result .= "graph_args --lower-limit 0 --slope-mode --units-exponent 1\n";
                }
            }
            $users = array_keys($stats);
            sort( $users );
            $allTotal = 0;
            $totalCdef = '';
            foreach ( $users as $user )
            {
                $fieldName = $user;
                $fieldName = preg_replace('/[^a-z]/','',$fieldName);
                if ( 'root' == $fieldName ) {
                    $fieldName = 'uroot';
                }
                if ( isset($stats[$user]['TOTAL']['jiffies_counter'])
                    && $stats[$user]['TOTAL']['jiffies_counter'] > 100 )
                {
                    if ( $config ) {
                        $result   .= sprintf( "%s.label %s\n", $fieldName, $user );
                        $result   .= sprintf( "%s.min 0\n", $fieldName );
                        $result   .= sprintf( "%s.draw LINE1\n", $fieldName );
                        if ( 'jiffies_counter' == $key ) {
                            $result   .= sprintf( "%s.type DERIVE\n", $fieldName );
                            $result   .= sprintf( "%s.max %d\n", $fieldName, $this->_maxJiffies );
                        }
                        if ( empty($totalCdef) ) {
                            $totalCdef = $fieldName;
                        } else {
                            $totalCdef .= sprintf( ',%s,+', $fieldName );
                        }
                    } else {
                        $result   .= sprintf( "%s.value %d\n", $fieldName, $stats[$user]['TOTAL'][$key] );
                        $allTotal += $stats[$user]['TOTAL'][$key];
                    }
                }
            } // end foreach( $users )
            if ( $config ) {
//                $result .= sprintf( "TOTAL.cdef %s\n", $totalCdef );
            } else {
                $allTotal = $this->_wrapFix( $allTotal );
                $result .= sprintf( "%s.value %d\n", 'TOTAL', $allTotal );
            }
            unset($user);
            unset($allTotal);
            unset($users);
            unset($totalCdef);
        }
        unset($urlSplit);
        unset($config);
        unset($baseUrl);
        unset($stats);
        return $result;
    }

    public function getMuninIoStats( $requestInfo ) {
        $result = '';
        $urlSplit = explode( '?', $requestInfo['URL'] );
        $baseUrl = reset( $urlSplit );
        $result .= sprintf( "# You can fetch the values from:  %s\n", $baseUrl );
        $result .= sprintf( "# You can fetch the config from:  %s?config\n", $baseUrl );
        $config = ( 'config' == $requestInfo['QUERY_STRING'] );
        $stats = $this->getProcStats();
        if ( is_array($stats) )
        {
            if ( $config ) {
                $result .= "graph_title IO per User\n";
                $result .= "graph_vlabel Bytes/sec  read(-) / write(+) \n";
                $result .= "graph_info MH Disk IO in bytes per second.\n";
                $result .= "graph_args --slope-mode\n";

                $fieldName = 'TOTAL';
                $result .= "graph_category system\n";
                $result .= "TOTAL_r.label TOTAL read\n";
                $result .= "TOTAL_r.draw LINE1\n";
                $result .= "TOTAL_r.colour eeeeee\n";
                $result .= "TOTAL_r.type DERIVE\n";
                $result .= "TOTAL_r.min 0\n";
                $result .= "TOTAL_r.max 12000000000\n"; // 12 Gigabit/sec
                $result .= sprintf( "%s_r.cdef %s_r,-1,*\n", $fieldName, $fieldName );
                $result .= "TOTAL_w.label TOTAL write\n";
                $result .= "TOTAL_w.draw LINE1\n";
                $result .= "TOTAL_w.colour eeeeee\n";
                $result .= "TOTAL_w.type DERIVE\n";
                $result .= "TOTAL_w.min 0\n";
                $result .= "TOTAL_w.max 12000000000\n"; // 12 Gigabit/sec
            }
            $users = array_keys($stats);
            sort( $users );
            $allTotal_r = 0;
            $allTotal_w = 0;
            $totalCdef = '';
            $colour = 0;
            foreach ( $users as $user ) {
                $fieldName = $user;
                $fieldName = preg_replace('/[^a-z]/','',$fieldName);
                if ( 'root' == $fieldName ) {
                    $fieldName = 'uroot';
                }
                if (   isset($stats[$user]['TOTAL']['ioread_counter'])
                    && isset($stats[$user]['TOTAL']['iowrite_counter'])
                    && ( $stats[$user]['TOTAL']['ioread_counter'] > 100 ||
                         $stats[$user]['TOTAL']['iowrite_counter'] > 100 ) )
                {
                    if ( $config ) {
                        $result   .= sprintf( "%s_r.label %s read\n", $fieldName, $user );
                        $result   .= sprintf( "%s_r.min 0\n", $fieldName );
                        $result   .= sprintf( "%s_r.max 12000000000\n", $fieldName ); // 12 Gigabit/sec
                        $result   .= sprintf( "%s_r.draw LINE1\n", $fieldName );
                        $result   .= sprintf( "%s_r.type DERIVE\n", $fieldName );
                        $result   .= sprintf( "%s_r.cdef %s_r,-1,*\n", $fieldName, $fieldName );
                        $result   .= sprintf( "%s_r.colour COLOUR%d\n",  $fieldName, $colour );
                        $result   .= sprintf( "%s_w.label %s write\n", $fieldName, $user );
                        $result   .= sprintf( "%s_w.min 0\n", $fieldName );
                        $result   .= sprintf( "%s_w.max 12000000000\n", $fieldName ); // 12 Gigabit/sec
                        $result   .= sprintf( "%s_w.draw LINE1\n", $fieldName );
                        $result   .= sprintf( "%s_w.type DERIVE\n", $fieldName );
                        $result   .= sprintf( "%s_w.colour COLOUR%d\n",  $fieldName, $colour );
                        if ( empty($totalCdef) ) {
                            $totalCdef = $fieldName;
                        } else {
                            $totalCdef .= sprintf( ',%s,+', $fieldName );
                        }
                    } else {
                        $result   .= sprintf( "%s_r.value %d\n", $fieldName, $stats[$user]['TOTAL']['ioread_counter'] );
                        $result   .= sprintf( "%s_w.value %d\n", $fieldName, $stats[$user]['TOTAL']['iowrite_counter'] );
                        $allTotal_r += $stats[$user]['TOTAL']['ioread_counter'];
                        $this->_wrapFix( $allTotal_r );
                        $allTotal_w += $stats[$user]['TOTAL']['iowrite_counter'];
                        $this->_wrapFix( $allTotal_w );
                    }
                    $colour++;
                }
            } // end foreach( $users )
            if ( ! $config ) {
                $result .= sprintf( "%s_r.value %d\n", 'TOTAL', $allTotal_r );
                $result .= sprintf( "%s_w.value %d\n", 'TOTAL', $allTotal_w );
            }
        }
        return $result;
    }

    /**
     * Returns a HTML layout detailed overview
     *
     * @param array $requestInfo
     * @return string - HTML for detailed overview
     */
    public function getProcStatsDetailHtml($requestInfo)
    {
        $getParam = array();
        if ( !empty($requestInfo['QUERY_STRING']) ) {
            parse_str($requestInfo['QUERY_STRING'], $getParam);
        }
        if (!empty($getParam['sort']) && isset($this->_sortOptions[$getParam['sort']])) {
            $this->_sortField = $this->_sortOptions[$getParam['sort']];
        } else {
            $this->_sortField = 'jiffies_counter';
        }
        $result       = 'Sort by: ';
        foreach ($this->_sortOptions as $key => $sortOption) {
            $result      .= ($this->_sortField == $sortOption) ? '<b>' : '';
            $result      .= sprintf('&nbsp;<a href="?sort=%s">%s</a> ', $key, ucfirst($key));
            $result      .= ($this->_sortField == $sortOption) ? '</b>' : '';
        }
        $result      .= '<br />';
        $result      .= '<table border="1" cellpadding="2" style="border-collapse:collapse; border-bottom:2px solid black; font-family: monospace;">'."\n";
        $result      .= '<tr>';
        $result      .= '<th>user</th>';
        $result      .= '<th colspan=7>current</th>';
        $result      .= '<th colspan=6>total</th>';
        $result      .= '</tr>'."\n";
        $result      .= '<tr>';
        $result      .= '<th>process</th>';
        $result      .= '<th colspan=1>procs</th>';
        $result      .= '<th colspan=2>jiff / sec</th>';
        $result      .= '<th colspan=2>read / sec</th>';
        $result      .= '<th colspan=2>write / sec</th>';
        $result      .= '<th colspan=2>jiff counter</th>';
        $result      .= '<th colspan=2>read bytes</th>';
        $result      .= '<th colspan=2>write bytes</th>';
        $result      .= '</tr>'."\n";

        $stats        = $this->getProcStats();
        if ( is_array($stats) )
        {
            $counterTotal = array();
            $valueTotal   = array();
            foreach ( $this->_counters as $counterName ) {
                $counterTotal[$counterName] = 0;
                $valueTotal[$counterName]   = 0;
            }
            $procsTotal   = 0;
            uasort( $stats, array($this,'_userCmp') );
            $users = array_keys($stats);
            foreach ( $users as $user )
            {
                if (   isset($stats[$user]['TOTAL']['procs']) ) {
                    $procsTotal   += $stats[$user]['TOTAL']['procs'];

                }
                foreach ( $this->_counters as $counterName ) {
                    $counterKey = $counterName.'_counter';
                    if (   isset($stats[$user]['TOTAL'][$counterKey])
                        && isset($stats[$user]['TOTAL'][$counterName]) ) {
                        $counterTotal[$counterName] += $stats[$user]['TOTAL'][$counterKey];
                        $valueTotal[$counterName]   += $stats[$user]['TOTAL'][$counterName];
                    }
                }
            }
            $stats['TOTAL']['TOTAL']['procs'] = $procsTotal;
            foreach ( $this->_counters as $counterName ) {
                $counterKey = $counterName.'_counter';
                $stats['TOTAL']['TOTAL'][$counterKey]  = $counterTotal[$counterName];
                $stats['TOTAL']['TOTAL'][$counterName] = $valueTotal[$counterName];
                // Set to at least 1 when empty to prevent divide by zero
                $counterTotal[$counterName] = max( 1, $counterTotal[$counterName] );
                $valueTotal[$counterName]   = max( 1, $valueTotal[$counterName] );
            }
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

                    foreach ( $this->_counters as $counterName ) {
                        $value  = empty($stats[$user][$process][$counterName]) ? 0 : $stats[$user][$process][$counterName];
                        if ( empty($value) )
                        {
                            $result      .= '<td width="30">&nbsp;</td>';
                            $result      .= '<td width="60">&nbsp;</td>';
                        }
                        else
                        {
                            $valuePerc    = $value * 100 / $valueTotal[$counterName];
                            $result      .= sprintf( '<td width="30" align="right">%d</td>', $value );
                            $result      .= sprintf( '<td width="60" align="right">%.02f%%</td>', $valuePerc );
                        }
                    }

                    foreach ( $this->_counters as $counterName ) {
                        $counterKey   = $counterName.'_counter';
                        $counter      = empty($stats[$user][$process][$counterKey]) ? 0 : $stats[$user][$process][$counterKey];
                        if ( empty($counter) )
                        {
                            $result      .= '<td>&nbsp;</td>';
                            $result      .= '<td>&nbsp;</td>';
                        }
                        else
                        {
                            $counterPerc  = $counter * 100 / $counterTotal[$counterName];
                            $result      .= sprintf( '<td align="right">%d</td>', $counter );
                            $result      .= sprintf( '<td align="right">%.02f%%</td>', $counterPerc );
                        }
                    }
                    $result      .= '</tr>'."\n";
                    unset($counter);
                    unset($counterPerc);
                    unset($jiff);
                    unset($jiffPerc);
                    unset($style);
                    unset($name);
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
     * It calls functions to collect data from /proc, does calculations and store it in the object in  $this->_dbData
     * It also the adds latest stats in front of the  $this->_statFifo  queue.
     */
    public function timerCollectStats()
    {
        $statData = $this->_collectProcStats();
        // echo $this->_debugTree( $statData ); // Useful for debugging, outputs to stdout in debug mode

        $userProcStats = $this->_getUserProcStats( $statData, $this->_prevStatData );
        $this->_prevStatData = $statData;

        foreach ( $userProcStats as $uid => &$nil )
        {
            foreach ( $userProcStats[$uid] as $process => &$nil2 )
            {
                if (  !isset( $this->_dbData[$uid][$process]['process'] ) ) {
                    $this->_dbData[$uid][$process]['linuxuser']         = $uid;
                    $this->_dbData[$uid][$process]['process']           = $process;
                }
                $this->_dbData[$uid][$process]['procs']             = $userProcStats[$uid][$process]['procs'];

                foreach ( $this->_counters as $counterName ) {
                    $counterKey = $counterName.'_counter';
                    $lastKey    = $counterName.'_last';
                    if (  !isset( $this->_dbData[$uid][$process][$counterKey] ) ) {
                        // No entry found in database, create initial data.
                        $this->_dbData[$uid][$process][$counterKey] = 0;
                    }

                    // Delta mechanism takes place in _getUserProcStats().
                    $this->_dbData[$uid][$process][$counterKey] += $userProcStats[$uid][$process][$counterName];
                    $this->_dbData[$uid][$process][$counterKey] = $this->_dbData[$uid][$process][$counterKey];
                    $this->_dbData[$uid][$process][$lastKey]    = $userProcStats[$uid][$process][$counterName] ;
                    // Copy counter to userProcStats to have it available in  $this->_statFifo
                    $userProcStats[$uid][$process][$counterKey] = $this->_dbData[$uid][$process][$counterKey];
                }
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

        // $this->_debugDbData();
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
        $rowSet = $this->_getDatabase()->query( 'SELECT linuxuser, process, '.
                                                'jiffies_last, jiffies_counter, '.
                                                'ioread_last,  ioread_counter, '.
                                                'iowrite_last, iowrite_counter '.
                                                'FROM procstats' );
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
        try {
            $updateSQL   =  'UPDATE procstats SET '.
                'jiffies_last=:jiffies_last, '.
                'jiffies_counter=:jiffies_counter, '.
                'ioread_last=:ioread_last, '.
                'ioread_counter=:ioread_counter, '.
                'iowrite_last=:iowrite_last, '.
                'iowrite_counter=:iowrite_counter '.
                'WHERE linuxuser=:linuxuser AND process=:process';
            $updateStmt  = $database->prepare($updateSQL);
            unset($updateSQL);
            $insertSQL   =  'INSERT INTO procstats '.
                '( linuxuser, process, jiffies_last, jiffies_counter, ioread_last, ioread_counter, iowrite_last, iowrite_counter) VALUES '.
                '(:linuxuser,:process,:jiffies_last,:jiffies_counter,:ioread_last,:ioread_counter,:iowrite_last,:iowrite_counter)';
            $insertStmt  = $database->prepare($insertSQL);
            unset($insertSQL);

            $linuxuser       = '';
            $process         = '';
            $jiffies_last    = '';
            $jiffies_counter = '';
            $ioread_last     = '';
            $ioread_counter  = '';
            $iowrite_last    = '';
            $iowrite_counter = '';

            // Bind parameters to statement variables
            $updateStmt->bindParam( ':linuxuser',       $linuxuser );
            $updateStmt->bindParam( ':process',         $process );
            $updateStmt->bindParam( ':jiffies_last',    $jiffies_last );
            $updateStmt->bindParam( ':jiffies_counter', $jiffies_counter );
            $updateStmt->bindParam( ':ioread_last',     $ioread_last );
            $updateStmt->bindParam( ':ioread_counter',  $ioread_counter );
            $updateStmt->bindParam( ':iowrite_last',    $iowrite_last );
            $updateStmt->bindParam( ':iowrite_counter', $iowrite_counter );

            $insertStmt->bindParam( ':linuxuser',       $linuxuser );
            $insertStmt->bindParam( ':process',         $process );
            $insertStmt->bindParam( ':jiffies_last',    $jiffies_last );
            $insertStmt->bindParam( ':jiffies_counter', $jiffies_counter );
            $insertStmt->bindParam( ':ioread_last',     $ioread_last );
            $insertStmt->bindParam( ':ioread_counter',  $ioread_counter );
            $insertStmt->bindParam( ':iowrite_last',    $iowrite_last );
            $insertStmt->bindParam( ':iowrite_counter', $iowrite_counter );

            // Loop through all messages and execute prepared insert statement
            if ( is_array($data) )
            {
                foreach ($data as $userData)
                {
                    if ( is_array($userData) )
                    {
                        foreach ($userData as $row)
                        {
                            if (  isset($row['linuxuser'])
                                && isset($row['process'])
                                && isset($row['jiffies_last'])
                                && isset($row['jiffies_counter'])
                                && isset($row['ioread_last'])
                                && isset($row['ioread_counter'])
                                && isset($row['iowrite_last'])
                                && isset($row['iowrite_counter'])
                            )
                            {
                                // Set values to bound variables
                                $linuxuser       = $row['linuxuser'];
                                $process         = $row['process'];
                                $jiffies_last    = $row['jiffies_last'];
                                $jiffies_counter = $row['jiffies_counter'];
                                $ioread_last     = $row['ioread_last'];
                                $ioread_counter  = $row['ioread_counter'];
                                $iowrite_last    = $row['iowrite_last'];
                                $iowrite_counter = $row['iowrite_counter'];

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
                unset($userData);
            }
            unset($data);

            $database->commit();
        } catch (Exception $e) {
            error_log('ProcStats: Error writing to SQLite database: '.$e->getMessage());
            $database->rollBack();
        }
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
     *                        $result[ PID ][ 'readByes' ]      = Integer
     *                        $result[ PID ][ 'writeBytes' ]    = Integer
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
     * @return array - Array with sums like:  $result[ USER ][ PROCESSNAME ][ 'time' ]    = unix time of getting info from /proc
     *                                        $result[ USER ][ PROCESSNAME ][ 'procs' ]   = number of processes
     *                                        $result[ USER ][ PROCESSNAME ][ 'jiffies' ] = sum of used jiffies since last collect
     *                                        $result[ USER ][ PROCESSNAME ][ 'ioread' ]  = bytes read
     *                                        $result[ USER ][ PROCESSNAME ][ 'iowrite' ] = bytes written
     */
    protected function _getUserProcStats( $statData, $prevStatData )
    {
        $result = array();
        //                Key in statData   Key in result
        $counters = array('thisJiff'     => 'jiffies',
                          'readBytes'    => 'ioread',
                          'writeBytes'   => 'iowrite');

        foreach ( $statData as $pid => $procInfo )
        {
            if ( isset($procInfo['uid']) ) {
                $uid      = $procInfo['uid'];
                $procName = $procInfo['name'];

                if (!isset($result[$uid])) {
                    $result[$uid] = array();
                }
                if (!isset($result[$uid][$procName])) {
                    $result[$uid][$procName] = array();
                    $result[$uid][$procName]['procs'] = 0;
                    foreach ( $counters as $prevKey => $resultKey ) {
                        $result[$uid][$procName][$resultKey] = 0;
                    }
                }

                $result[$uid][$procName]['procs']++;
                foreach ( $counters as $prevKey => $resultKey ) {
                    $value = $procInfo[$prevKey];
                    if (isset($prevStatData[$pid][$prevKey])) {
                        // Process was already running previous collect time
                        $value = $value - $prevStatData[$pid][$prevKey];
                        $value = max(0, $value); // make sure it does not get negative
                    }
                    $result[$uid][$procName][$resultKey] += $value;
                }
                $result[$uid][$procName]['time'] = $procInfo['time'];

                unset($uid);
                unset($procName);
                unset($iJiff);
            }
        }

        foreach ($prevStatData as $pid => $prevProcInfo) {
            if ( !isset($statData[$pid]) ) {
                // Process has died since last collection of stats
                if ( !empty($prevProcInfo['parentPid']) ) {
                    $parentPid = $prevProcInfo['parentPid'];
                    if ( !empty($statData[$parentPid]) && isset($statData[$parentPid]['uid']) ) {
                        $uid = $statData[$parentPid]['uid'];
                        $procName = $statData[$parentPid]['name'];
                        // When a child ends, all IO counters are added to the parent.
                        // We substract ended child io bytes from parent, because we keep the ended child's data.
                        $result[$uid][$procName]['ioread'] -= $prevProcInfo['readBytes'];
                        $result[$uid][$procName]['iowrite'] -= $prevProcInfo['writeBytes'];
                    }
                }
            }
        }

        unset($procInfo);

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
        $procStatFile   = $this->_procPath.'/'.$pid.'/stat';
        $procStatusFile = $this->_procPath.'/'.$pid.'/status';
        $procIoFile     = $this->_procPath.'/'.$pid.'/io';

        try
        {
            $fMicroTime = microtime(true);

            //// Read Stat Data
            $fh = fopen($procStatFile, 'r');
            $statLine = fread($fh, 1024);
            fclose($fh);
            unset($fh);

            // Read IO Data
            $fh = fopen($procIoFile, 'r');
            $ioData = fread($fh, 4096);
            fclose($fh);
            unset($fh);

            //// Read Status Data
            $fh           = fopen($procStatusFile, 'r');
            $statusData   = fread($fh, 4096);
            fclose($fh);
            unset($fh);

            //// Process Status Data
            $uid   = null;
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
            unset($statusData);

            //// Process Stat Data
            $statFields   = explode( ' ', $statLine );
            unset($statLine);
            if ( 17 <= count($statFields) )
            {
                // For info about the '/proc/PID/stat' fields:
                // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
                // search for "/proc/[pid]/stat"

                $process      = $statFields[1];
                $process      = trim( $process, ':()-0123456789/' );
                $process      = preg_replace('/\/\d+$/','',$process);
                if ( 1 == $pid) {
                    $process = '[init]';
                }
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

                unset($uid);
                unset($process);
                unset($parentPid);
            }
            unset($statFields);

            //// Process IO Data
            // For info about the '/proc/PID/io' fields:
            // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
            // search for "/proc/[pid]/io"
            $ioLines = explode("\n",$ioData);
            $match = array();
            foreach( $ioLines as $ioLine ) {
                if ( preg_match('/^read_bytes:\s+(\d+)/',$ioLine,$match) ) {
                    $result[$pid]['readBytes'] = $match[1];
                }
                if ( preg_match('/^write_bytes:\s+(\d+)/',$ioLine,$match) ) {
                    $result[$pid]['writeBytes'] = $match[1];
                }
            }
            unset($match);
            unset($ioLines);
            unset($ioData);
        }
        catch ( Exception $e )
        {
            // For performance reasons we do not check if the files we read do
            // exist. Then this exception is hit.
        }

        unset($procStatFile);
        unset($procStatusFile);
        unset($procIoFile);
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
        if ( 1 == $pid ) {
            $result .= 'PID - NAME - USER | thisJiff / deadChildJiff | R readBytes | W writeBytes |';
            $result .= "\n\n";
        }
        $result .= str_repeat(' ',$level*8);
        $result .= $pid . ' - '.$statData[$pid]['name'];
        $result .= ' - '.$this->_uidToUser($statData[$pid]['uid']);
        $result .= ' | ';
        $result .= $statData[$pid]['thisJiff'];
        $result .= ' / ';
        $result .= $statData[$pid]['deadChildJiff'];
        $result .= ' | R ';
        $result .= $statData[$pid]['readBytes'];
        $result .= ' | W ';
        $result .= $statData[$pid]['writeBytes'];
        $result .= "\n";
        foreach ( $statData[$pid]['childPids'] as $childPid )
        {
            $result .= $this->_debugTree( $statData, $childPid, $level+1 );
        }
        return $result;
    }

    protected function _debugDbData() {
        foreach( $this->_dbData as $uid => &$nil ) {
            foreach ( $this->_dbData[$uid] as $process => $procData )
            {
                printf( "uid:%-5s %-15s procs:%-3s jiff_cnt:%-10s jiff_last:%-5s  read_cnt:%-10s read_last:%-5s  write_cnt:%-10s write_last:%-5s\n",
                        $procData['linuxuser'],
                        $procData['process'],
                        $procData['procs'],
                        $procData['jiffies_counter'],
                        $procData['jiffies_last'],
                        $procData['ioread_counter'],
                        $procData['ioread_last'],
                        $procData['iowrite_counter'],
                        $procData['iowrite_last'] );
            }
        }
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
        return $b['TOTAL'][$this->_sortField] - $a['TOTAL'][$this->_sortField];
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
        return $b[$this->_sortField] - $a[$this->_sortField];
    }

    /**
     * Make sure the number fits in an unsigned 32 bits integer, wrap around if not.
     *
     * @param int|float $number
     * @return int
     */
    protected function _wrapFix( $number )
    {
        $number = $number % pow(2,32);
        return $number;
    }

}
