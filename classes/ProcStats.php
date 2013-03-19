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
        $users = array_keys($stats);
        $allTotal = 0;
        foreach ( $users as $user )
        {
            $result .= sprintf( '%s:%d ', $user, $stats[$user]['TOTAL']['counter'] );
            $allTotal += $stats[$user]['TOTAL']['counter'];
        }
        $result .= sprintf( '%s:%d ', 'TOTAL', $allTotal );
        unset($stats);
        unset($users);
        return $result;
    }

    public function getNagiosProcStats($path, $queryString)
    {
        $result = '';
        $stats = $this->getProcStats();
        $users = array_keys($stats);
        $result .= 'OK | ';
        $allTotal = 0;
        foreach ( $users as $user )
        {
            $result .= sprintf( '%s=%dc ', $user, $stats[$user]['TOTAL']['counter'] );
            $allTotal += $stats[$user]['TOTAL']['counter'];
        }
        $result .= sprintf( '%s=%dc ', 'TOTAL', $allTotal );
        unset($stats);
        unset($users);
        return $result;
    }


    public function collectProcStats()
    {
        $this->_collectProcStats();
        //$this->_debugTree();

        $userProcStats = $this->_getUserProcStats();

        foreach ( $userProcStats as $user => &$nil ) {
            foreach ( $userProcStats[$user] as $name => &$nil2 ) {
                if ( !empty( $this->_dbData[$user][$name] ) ) {
                    $delta = $userProcStats[$user][$name]['jiff'] - $this->_dbData[$user][$name]['lastvalue'];
                    $delta = max( 0, $delta );
                    // Previous data found in database
                    $this->_dbData[$user][$name]['counter'] += $delta;
                }
                else {
                    // No entry found in database, create initial data.
                    $this->_dbData[$user][$name]['linuxuser'] = $user;
                    $this->_dbData[$user][$name]['process']   = $name;
                    $this->_dbData[$user][$name]['counter']   = $userProcStats[$user][$name]['jiff'];
                }
                $this->_dbData[$user][$name]['lastvalue']      = $userProcStats[$user][$name]['jiff'] ;
                $userProcStats[$user][$name]['counter'] = $this->_dbData[$user][$name]['counter'];
            }
            unset($nil2);
        }
        unset( $nil );

        if ($this->_statFifoLen <= count($this->_statFifo) )
        {
            array_pop($this->_statFifo);
        }
        array_unshift($this->_statFifo,$userProcStats);
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
        if ( empty($this->_database) ) {
            if ( !file_exists($sqliteFile) ) {
                if ( file_exists($templateFile) ) {
                    $copyOK = copy( $templateFile, $sqliteFile );
                    if ( !$copyOK ) {
                        throw new Exception('Copy of SQLite file from template "'.$templateFile.'" to "'.$sqliteFile.'" failed.');
                    }
                }
                else {
                    throw new Exception('SQLite file does not exist, and template database file does also not exist.');
                }
            }
            // Create (connect to) SQLite database in file
            $this->_database = new PDO('sqlite:'.$sqliteFile);
            // Set errormode to exceptions
            $this->_database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
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
        $database = $this->_getDatabase();
        $rowset   = $database->query('SELECT linuxuser,process,lastvalue,counter FROM jiffy');
        foreach ($rowset as $row) {
            $result[ $row['linuxuser'] ][ $row['process'] ] = $row;
        }
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
        $insertSQL   = 'INSERT INTO jiffy (linuxuser,process,lastvalue,counter) VALUES (:linuxuser,:process,:lastvalue,:counter)';
        $insertStmt  = $database->prepare($insertSQL);

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
        foreach ($data as $userdata) {
            foreach ($userdata as $row) {
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

        $database->commit();
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

            while (false !== ($entry = readdir($dirHandle))) {
                if ( is_numeric($entry) ) {
                    $this->_getProcInfo( $entry );
                }
            }
        }
        closedir( $dirHandle );
    }

    /**
     * @return array
     */
    private function _getUserProcStats()
    {
        $result = array();

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
            // TODO: Do we want to take in account jiffies from dead children?
            //       Currently it acts strange after ending a Siege.
            //$iJiff += $aProcInfo['deadChildJiff'];

            $result[$uid][$procName]['jiff'] += $iJiff;
            $result[$uid][$procName]['time'] = $aProcInfo['time'];
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

        try {
            //$lines        = file( $procStatFile );
            //$statLine     = reset( $lines );

            $fh = fopen($procStatFile, 'r');
            $statLine = fread($fh, 1024);
            fclose($fh);

            $statFields   = explode( ' ', $statLine );
            $uid          = null;

            // $statusdata = file_get_contents( $procStatusFile );
            $fh = fopen($procStatusFile, 'r');
            $statusdata = fread($fh, 4096);
            fclose($fh);

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

            // For info about the '/proc/PID/stat' fields:
            // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
            // search for "/proc/[pid]/stat"

            $name      = $statFields[1];
            $name      = trim( $name, '()-0123456789/' );
            $name      = preg_replace('/\/\d+$/','',$name);
            $parentPid = $statFields[3];

            if ( !isset( $this->_statData[$pid]['childPids'] ) ) {
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

            $this->_statData[$pid]['time'] = microtime(true);
        }
        catch ( Exception $e )
        {
            // For performance reasons we do not check if the files we read do
            // exist. Then this exception is hit.
        }
    }

    /**
     * Get username for a user id.
     *
     * @param int $uid - User ID to get username for
     * @return string  - Username
     */
    function _uidToUser( $uid )
    {
        if ( !isset($this->_uidcache[$uid]) ) {
            $aUserInfo = posix_getpwuid($uid);
            if ( empty($aUserInfo['name']) ) {
                $this->_uidcache[$uid] =  '['.$uid.']';
            }
            else {
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
}
