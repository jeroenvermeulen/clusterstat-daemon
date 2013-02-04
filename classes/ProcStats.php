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

    /**
     * Constructor - initialize some data.
     */
    public function __construct()
    {
        $this->_workDir = dirname( dirname(__FILE__) );
    }

    /**
     * Destructor - clean up stuff
     */
    public function __destruct()
    {
        $this->_database = null; // PDO will cleanup the connection
    }

    /**
     * Renders the web page of the process statistics
     *
     * @param String $path The path of the http request
     * @param String $queryString The querystring of the http request
     *
     * @return String Html contents of the homepage
     */
    public function getWebPage($path, $queryString) {
        $template = new HtmlTemplate('procstats.tpl');
        $template->setVar('procstats', $this->getProcStats($path, $queryString) );
        return $template->parse();
    }

    /**
     * Returns a JSON array with process statitics
     *
     * @return array Process statistics.
     */
    public function getProcStats($path, $queryString)
    {
        $result = array();
        if ($this->_statFifoLen <= count($this->_statFifo) )
        {
            $startStats = end( $this->_statFifo );
            $endStats = reset( $this->_statFifo );
            $users = array_keys( $startStats );
            foreach ( $users as $user )
            {
                $result[$user]['TOTAL']['jiff']    = 0;
                $result[$user]['TOTAL']['counter'] = 0;
                $procs = array_keys( $startStats[$user] );
                foreach ( $procs as $proc )
                {
                    if (  !empty($startStats[$user][$proc]['jiff'])
                       && !empty($endStats[$user][$proc]['jiff'])
                       )
                    {
                        $timeDiff = $endStats[$user][$proc]['time'] - $startStats[$user][$proc]['time'];
                        $diffJiff = $endStats[$user][$proc]['jiff'] - $startStats[$user][$proc]['jiff'];
                        $diffJiff = round( $diffJiff / $timeDiff );
                        $diffJiff = max( 0, $diffJiff );
                        $result[$user][$proc]['jiff']    = max( 0, $diffJiff );
                        $result[$user][$proc]['counter'] = $endStats[$user][$proc]['jiff'];
                        $result[$user]['TOTAL']['jiff']    += max( 0, $diffJiff );
                        $result[$user]['TOTAL']['counter'] += $endStats[$user][$proc]['jiff'];
                    }
                }
            }
        }
        return $this->_jsonIndent( json_encode($result) );
        //return json_encode($result);
    }

    public function collectProcStats()
    {
        $this->_collectProcStats();
        //$this->_debugTree();

        $userProcStats = $this->_getUserProcStats();

        $dbData = $this->_getFromDatabase();
        $users = array_keys( $userProcStats );
        foreach ( $users as $user ) {
            $names = array_keys( $userProcStats[$user] );
            foreach ( $names as $name ) {
                if ( !empty( $dbData[$user][$name] ) ) {
                    if ( $dbData[$user][$name]['lastvalue'] > $userProcStats[$user][$name]['jiff'] ) {
                        $dbData[$user][$name]['offset'] += $dbData[$user][$name]['lastvalue'];
                    }
                }
                else {
                    $dbData[$user][$name]['linuxuser'] = $user;
                    $dbData[$user][$name]['process']   = $name;
                    $dbData[$user][$name]['offset']    = 0;
                }
                $dbData[$user][$name]['lastvalue']   = $userProcStats[$user][$name]['jiff'] ;
                $userProcStats[$user][$name]['jiff'] += $dbData[$user][$name]['offset'];
            }
        }
        $this->_writeToDatabase( $dbData );

        if ($this->_statFifoLen <= count($this->_statFifo) )
        {
            array_pop($this->_statFifo);
        }
        array_unshift($this->_statFifo,$userProcStats);
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
        $rowset   = $database->query('SELECT linuxuser,process,lastvalue,offset FROM jiffy');
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
        $database = $this->_getDatabase();
        $sql = 'REPLACE INTO jiffy (linuxuser,process,lastvalue,offset) VALUES (:linuxuser,:process,:lastvalue,:offset)';
        $stmt = $database->prepare($sql);

        $linuxuser   = '';
        $process     = '';
        $lastvalue   = '';
        $offset      = '';

        // Bind parameters to statement variables
        $stmt->bindParam( ':linuxuser',   $linuxuser );
        $stmt->bindParam( ':process',     $process );
        $stmt->bindParam( ':lastvalue',   $lastvalue );
        $stmt->bindParam( ':offset',      $offset );

        // Loop through all messages and execute prepared insert statement
        foreach ($data as $userdata) {
            foreach ($userdata as $row) {
                // Set values to bound variables
                $linuxuser   = $row['linuxuser'];
                $process     = $row['process'];
                $lastvalue   = $row['lastvalue'];
                $offset      = $row['offset'];

                // Execute statement
                $stmt->execute();
            }
        }
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
            $user     = $aProcInfo['user'];
            $procName = $aProcInfo['name'];

            if ( !isset( $result[$user][$procName] ) )
            {
                $result[$user][$procName]['procs'] = 0;
                $result[$user][$procName]['jiff'] = 0;
            }

            $result[$user][$procName]['procs']++;
            $iJiff = $aProcInfo['thisJiff'];
            //$iJiff += $aProcInfo['deadChildJiff'];

            $result[$user][$procName]['jiff'] += $iJiff;
            $result[$user][$procName]['time'] = $aProcInfo['time'];
        }

        ksort( $result );
        $users = array_keys( $result );
        foreach ( $users as $user )
        {
            ksort( $result[$user] );
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

        if (  is_readable($procStatFile)
           && is_readable($procStatusFile)
           )
        {
            $lines        = file( $procStatFile );
            $statLine     = reset( $lines );
            $statFields   = preg_split( '|\s+|', $statLine );
            $statusFields = array();

            $lines = file( $procStatusFile );
            foreach ( $lines as $line )
            {
                $match = array();
                if ( preg_match( '/^(\w+):\s*(.+)$/', $line, $match ) )
                {
                    // Info about the '/proc/PID/status' fields:
                    // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
                    // search for "/proc/[pid]/status"
                    $statusFields[ $match[1] ] = $match[2];
                }
            }

            $uids = preg_split( '/\s+/', $statusFields['Uid'] );
            $uid  = ( empty($uids[1]) ) ? fileowner($procStatFile) : $uids[1];

            // For info about the '/proc/PID/stat' fields:
            // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
            // search for "/proc/[pid]/stat"

            $name      = $statFields[1];
            $name      = trim( $name, '()-' );
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
            $this->_statData[$pid]['user'] = $this->_uidToUser($uid);

            $this->_statData[$pid]['thisJiff'] = 0;
            $this->_statData[$pid]['thisJiff'] += $statFields[13]; // utime = user mode
            $this->_statData[$pid]['thisJiff'] += $statFields[14]; // stime = kernel mode

            $this->_statData[$pid]['deadChildJiff'] = 0;
            $this->_statData[$pid]['deadChildJiff'] += $statFields[15]; // cutime = ended children user mode
            $this->_statData[$pid]['deadChildJiff'] += $statFields[16]; // cstime = ended children kernel mode

            $this->_statData[$pid]['time'] = microtime(true);
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
        if ( empty($this->_uidcache[$uid]) ) {
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
