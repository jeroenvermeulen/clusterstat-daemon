<?php

class ProcStats
{
    protected $_procPath   = '/proc';
    protected $_statData   = array();
    protected $_database   = null;
    protected $_uidcache   = array();
    protected $_sqliteFile = 'userstats.sqlite';
    protected $_statFifo   = array();
    protected $_statFifoLen = 3;
    protected $_workDir    = null;
    protected $_dbData     = null;

    /**
     * Constructor - initialize some data.
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
     * Returns a JSON array with process statitics
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
                foreach ( $endStats as $uid => &$nil )
                {
                    $user = $this->_uidToUser($uid);

                    $result[$user]['TOTAL']['jiff']    = 0;
                    $result[$user]['TOTAL']['counter'] = 0;

                    if ( is_array($startStats[$uid]) )
                    {
                        foreach ( $startStats[$uid] as $proc => &$nil2 )
                        {
                            if (  !empty($startStats[$uid][$proc]['jiff'])
                               && !empty($endStats[$uid][$proc]['jiff'])
                               )
                            {
                                $timeDiff = $endStats[$uid][$proc]['time'] - $startStats[$uid][$proc]['time'];
                                $diffJiff = $endStats[$uid][$proc]['jiff'] - $startStats[$uid][$proc]['jiff'];
                                $diffJiff = round( $diffJiff / $timeDiff );
                                $diffJiff = max( 0, $diffJiff );
                                $result[$user][$proc]['jiff']    = max( 0, $diffJiff );
                                $result[$user][$proc]['counter'] = $endStats[$uid][$proc]['counter'];
                                $result[$user]['TOTAL']['jiff']    += max( 0, $diffJiff );
                                $result[$user]['TOTAL']['counter'] += $endStats[$uid][$proc]['counter'];
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

    public function getJsonProcStats($path, $queryString)
    {
        //return $this->_jsonIndent( json_encode($result) );
        return json_encode($this->getProcStats());
    }

    public function getCactiProcStats($path, $queryString)
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

    public function getNagiosProcStats($path, $queryString)
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
                $result .= sprintf( '%s=%dc ', $user, $stats[$user]['TOTAL']['counter'] );
                $allTotal += $stats[$user]['TOTAL']['counter'];
            }
            $result .= sprintf( '%s=%dc ', 'TOTAL', $allTotal );
            unset($user);
            unset($allTotal);
            unset($users);
        }
        unset($stats);
        return $result;
    }

    public function getProcStatsDetailHtml($path, $queryString)
    {
        $result       = '';
        $result      .= '<table border="1" cellpadding="2" style="border-collapse:collapse; border-bottom:2px solid black;">'."\n";
        $result      .= '<tr>';
        $result      .= '<th>user<br />process</th>';
        $result      .= '<th colspan=2>current<br />jiff / sec</th>';
        $result      .= '<th colspan=2>total<br />counter</th>';
        $result      .= '</tr>'."\n";
        $stats        = $this->getProcStats();
        if ( is_array($stats) )
        {
            $counterTotal = 0;
            $jiffTotal    = 0;
            uasort( $stats, array($this,'_userCmp') );
            $users = array_keys($stats);
            foreach ( $users as $user )
            {
                $counterTotal += $stats[$user]['TOTAL']['counter'];
                $jiffTotal    += $stats[$user]['TOTAL']['jiff'];
            }
            $stats['TOTAL']['TOTAL']['counter'] = $counterTotal;
            $stats['TOTAL']['TOTAL']['jiff']    = $jiffTotal;
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
                foreach ( $procs as $proc )
                {
                    if ( !empty($stats[$user]['TOTAL']['counter']) )
                    {
                        $counter      = $stats[$user][$proc]['counter'];
                        $counterPerc  = $counter * 100 / $counterTotal;
                        $jiff         = $stats[$user][$proc]['jiff'];
                        $jiffPerc     = $jiff * 100 / $jiffTotal;
                        $style        = '';
                        $name         = $proc;
                        if ( 'TOTAL' == $user )
                        {
                            $style    = 'background-color:#74B4CA; border-top:2px solid black;';
                        }
                        elseif ( 'TOTAL' == $proc )
                        {
                            $style    = 'background-color:#C8DAE6; border-top:2px solid black;';
                            $name     = $user;
                        }
                        $result      .= sprintf( '<tr style="%s">', $style);
                        $result      .= sprintf( '<td>%s</td>', $name );
                        if ( empty($jiff) )
                        {
                            $result      .= '<td>&nbsp;</td>';
                            $result      .= '<td>&nbsp;</td>';
                        }
                        else
                        {
                            $result      .= sprintf( '<td align="right">%d</td>', $jiff );
                            $result      .= sprintf( '<td align="right">%.02f%%</td>', $jiffPerc );
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
                unset($proc);
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

    public function collectProcStats()
    {
        $this->_collectProcStats();
        //$this->_debugTree();

        $userProcStats = $this->_getUserProcStats();

        if ( is_array($userProcStats) )
        {
            foreach ( $userProcStats as $user => &$nil )
            {
                if ( is_array($userProcStats[$user]) )
                {
                    foreach ( $userProcStats[$user] as $name => &$nil2 )
                    {
                        if ( !empty( $this->_dbData[$user][$name] ) )
                        {
                            $delta = $userProcStats[$user][$name]['jiff'] - $this->_dbData[$user][$name]['lastvalue'];
                            $delta = max( 0, $delta );
                            // Previous data found in database
                            $this->_dbData[$user][$name]['counter'] += $delta;
                            unset($delta);
                        }
                        else
                        {
                            // No entry found in database, create initial data.
                            $this->_dbData[$user][$name]['linuxuser'] = $user;
                            $this->_dbData[$user][$name]['process']   = $name;
                            $this->_dbData[$user][$name]['counter']   = $userProcStats[$user][$name]['jiff'];
                        }
                        $this->_dbData[$user][$name]['lastvalue'] = $userProcStats[$user][$name]['jiff'] ;
                        $userProcStats[$user][$name]['counter']   = $this->_dbData[$user][$name]['counter'];
                    }
                    unset($nil2);
                    unset($name);
                }
            }
            unset($nil);
            unset($user);

            if ($this->_statFifoLen <= count($this->_statFifo) )
            {
                array_pop($this->_statFifo);
            }
            array_unshift($this->_statFifo,$userProcStats);
        }

        unset($userProcStats);
    }

    /**
     * To be called by timer
     */
    function writeDatabase()
    {
        $this->_writeToDatabase( $this->_dbData );
    }

    /**
     * Get an SQLite database connection
     *
     * @return PDO
     */
    private function _getDatabase()
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
    private function _getFromDatabase()
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
    private function _writeToDatabase( $data )
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
     * Loop trough all process dirs in /proc and collect process data.
     * The process data is saved within this class.
     *
     * @throws Exception
     */
    private function _collectProcStats()
    {
        $dirHandle = opendir($this->_procPath);
        if ( false === $dirHandle )
        {
            throw new Exception('Could not open proc filesystem in "'.$this->_procPath.'".');
        }
        else
        {
            $this->_statData = array();

            while (false !== ($entry = readdir($dirHandle)))
            {
                if ( is_numeric($entry) )
                {
                    $this->_getProcInfo( $entry );
                }
            }
            unset($entry);
            closedir( $dirHandle );
        }
        unset($dirHandle);
    }

    /**
     * @return array
     */
    private function _getUserProcStats()
    {
        $result = array();

        if ( is_array($this->_statData) )
        {
            foreach ( $this->_statData as $aProcInfo )
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

                $result[$uid][$procName]['jiff'] += $iJiff;
                $result[$uid][$procName]['time'] = $aProcInfo['time'];

                unset($uid);
                unset($procName);
                unset($iJiff);
            }
            unset($aProcInfo);
        }

        return $result;
    }

    /**
     * Get the details from /proc for one process ID.
     *
     * @param $pid integer - Process ID to get the data for.
     */
    private function _getProcInfo( $pid )
    {
        $procStatFile =   $this->_procPath.'/'.$pid.'/stat';
        $procStatusFile = $this->_procPath.'/'.$pid.'/status';

        try
        {
            $fMicroTime = microtime(true);

            $fh = fopen($procStatFile, 'r');
            $statLine = fread($fh, 1024);
            fclose($fh);
            unset($fh);

            $statFields   = explode( ' ', $statLine );
            $uid          = null;

            // $statusdata = file_get_contents( $procStatusFile );
            $fh = fopen($procStatusFile, 'r');
            $statusdata = fread($fh, 4096);
            fclose($fh);
            unset($fh);

            $match = array();
            if ( preg_match( '/Uid:\s*\d+\s*(\d+)/', $statusdata, $match ) )
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

            // For info about the '/proc/PID/stat' fields:
            // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
            // search for "/proc/[pid]/stat"

            $name      = $statFields[1];
            $name      = trim( $name, '()-0123456789/' );
            $name      = preg_replace('/\/\d+$/','',$name);
            $parentPid = $statFields[3];

            if ( !isset( $this->_statData[$pid]['childPids'] ) )
            {
                $this->_statData[$pid]['childPids'] = array();
            }
            $this->_statData[$pid]['pid']       = $pid;
            $this->_statData[$pid]['name']      = $name;
            $this->_statData[$pid]['parentPid'] = $parentPid;
            if ( !empty($parentPid) ) {
                $this->_statData[$parentPid]['childPids'][] = $pid;
            }

            $this->_statData[$pid]['uid']  = $uid;

            $this->_statData[$pid]['thisJiff'] = 0;
            $this->_statData[$pid]['thisJiff'] += $statFields[13]; // utime = user mode
            $this->_statData[$pid]['thisJiff'] += $statFields[14]; // stime = kernel mode

            $this->_statData[$pid]['deadChildJiff'] = 0;
            $this->_statData[$pid]['deadChildJiff'] += $statFields[15]; // cutime = ended children user mode
            $this->_statData[$pid]['deadChildJiff'] += $statFields[16]; // cstime = ended children kernel mode

            $this->_statData[$pid]['time'] = $fMicroTime;

            unset($statFields);
            unset($fMicroTime);
            unset($uid);
            unset($name);
            unset($parentPid);
            unset($pid);
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
    function _uidToUser( $uid )
    {
        if ( !isset($this->_uidcache[$uid]) )
        {
            $aUserInfo = posix_getpwuid($uid);
            if ( empty($aUserInfo['name']) )
            {
                $this->_uidcache[$uid] =  '['.$uid.']';
            }
            else
            {
                $this->_uidcache[$uid] = $aUserInfo['name'];
            }
        }
        return $this->_uidcache[$uid];
    }

    /**
     * Recursive function to print tree with process info, for debugging.
     *
     * @param int $pid   - Process Id to print info for
     * @param int $level - Nesting level
     */
    function _debugTree( $pid=1, $level=0 )
    {
        echo str_repeat(' ',$level*8);
        echo $pid . ' - '.$this->_statData[$pid]['name'];
        echo ' - '.$this->_uidToUser($this->_statData[$pid]['uid']);
        echo ' | ';
        echo $this->_statData[$pid]['thisJiff'];
        echo ' / ';
        echo $this->_statData[$pid]['deadChildJiff'];
        echo "\n";
        foreach ( $this->_statData[$pid]['childPids'] as $childPid )
        {
            $this->_debugTree( $childPid, $level+1 );
        }
    }

    /**
     * Indent a JSON string to be more readable for debugging
     *
     * @param string $json
     * @return string
     */
    private function _jsonIndent($json)
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

    function _userCmp($a, $b)
    {
        return $b['TOTAL']['counter'] - $a['TOTAL']['counter'];
    }

    function _userProcCmp($a, $b)
    {
        return $b['counter'] - $a['counter'];
    }

}
