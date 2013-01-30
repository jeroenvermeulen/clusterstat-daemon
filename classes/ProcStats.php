<?php
/**
 * Created by JetBrains PhpStorm.
 * User: jeroen
 * Date: 30/01/13
 * Time: 13:29
 * To change this template use File | Settings | File Templates.
 */
class ProcStats
{
    protected $procPath  = '/proc';
    protected $statData  = array();
    protected $totalData = array();
    protected $database  = null;

    public function __construct()
    {
        $this->statData = array();
        $this->totalData['procs'] = 0;
        $this->totalData['jiff'] = 0;
    }

    public function __destruct()
    {
        $this->database = null; // PDO will cleanup the connection
    }

    /**
     * Returns a JSON array of runtime information about the workermanger and its host system
     * @return array Runtime information array
     */
    public function getProcStats($path, $queryString) {
        $this->_collectProcStats();
        $aUserProcStats = $this->_getUserProcStats();
        $aUserProcStats['TOTAL'] = $this->totalData;
        $dbData = $this->_getFromDatabase();
        foreach ( $aUserProcStats as $user => $userdata )
        {
            if ( !empty($dbData[$user]) )
            {
                if ( $dbData[$user]['lastvalue'] > $userdata['jiff'] )
                {
                    $dbData[$user]['offset'] += $dbData[$user]['lastvalue'];
                }
            }
            else
            {
                $dbData[$user]['linuxuser'] = $user;
                $dbData[$user]['offset'] = 0;
            }
            $dbData[$user]['lastvalue']     = $userdata['jiff'] ;
            $aUserProcStats[$user]['jiff'] += $dbData[$user]['offset'];
        }
        $this->_writeToDatabase( $dbData );
        return json_encode( $aUserProcStats );
    }

    /**
     * @return PDO
     */
    private function _getDatabase()
    {
        if ( empty($this->database) )
        {
            // Create (connect to) SQLite database in file
            $this->database = new PDO('sqlite:userstats.sqlite3');
            // Set errormode to exceptions
            $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $this->database;
    }

    private function _getFromDatabase()
    {
        $result = array();
        $database = $this->_getDatabase();
        $rowset   = $database->query('SELECT linuxuser,lastvalue,offset FROM jiffy');
        foreach ($rowset as $row) {
            $result[ $row['linuxuser'] ] = $row;
        }
        return $result;
    }

    private function _writeToDatabase( $data )
    {
        $database = $this->_getDatabase();
        $sql = 'REPLACE INTO jiffy (linuxuser,lastvalue,offset) VALUES (:linuxuser,:lastvalue,:offset)';
        $stmt = $database->prepare($sql);

        $user = '';
        $lastvalue = '';
        $offset = '';

        // Bind parameters to statement variables
        $stmt->bindParam(':linuxuser',      $user);
        $stmt->bindParam(':lastvalue', $lastvalue);
        $stmt->bindParam(':offset',    $offset);

        // Loop thru all messages and execute prepared insert statement
        foreach ($data as $row) {
            // Set values to bound variables
            $user      = $row['linuxuser'];
            $lastvalue = $row['lastvalue'];
            $offset    = $row['offset'];

            // Execute statement
            $stmt->execute();
        }
    }

    private function _collectProcStats()
    {
        $aAllProcStat = glob($this->procPath.'/*/stat');

        foreach ( $aAllProcStat as $sProcStatFile )
        {
            $sProcDir = dirname($sProcStatFile);
            $iProcId  = basename($sProcDir);
            if ( is_numeric($iProcId) )
            {
                $this->_getProcInfo( $iProcId );
            }
        }
    }

    private function _getUserProcStats()
    {
        $aUserProcStat = array();

        foreach ( $this->statData as $aProcInfo )
        {
            $iUid     = $aProcInfo['iUid'];

            if (  isset($aProcInfo['iParent'])
               && isset($this->statData[$aProcInfo['iParent']]['iUid'])
               )
            {
                if ( $iUid == $this->statData[$aProcInfo['iParent']]['iUid'] )
                {
                    // Parent process has the same user id, we don't count this child's jiffies
                    $aUserProcStat[$iUid]['procs']++;
                    continue;
                }
            }

            if ( !isset($aUserProcStat[$iUid]) )
            {
                $aUserProcStat[$iUid]['procs'] = 0;
                $aUserProcStat[$iUid]['jiff'] = 0;
            }

            $iJiff = $aProcInfo['iThisJiff'];
            $iJiff += $aProcInfo['iChildJiff'];

            if ( isset($aProcInfo['aChildPids']) )
            {
                foreach ( $aProcInfo['aChildPids'] as $iChildPid )
                {
                    if ( isset($this->statData[$iChildPid]['iUid'])
                       &&  $iUid != $this->statData[$iChildPid]['iUid'] )
                    {
                        // Child has other owner, substract Jiffies
                        if ( !empty($this->statData[$iChildPid]['iThisJiff']) )
                        {
                            $iJiff -= $this->statData[$iChildPid]['iThisJiff'];
                        }
                        if ( !empty($this->statData[$iChildPid]['iChildJiff']) )
                        {
                            $iJiff -= $this->statData[$iChildPid]['iChildJiff'];
                        }
                    }
                }
            }

            $aUserProcStat[$iUid]['procs']++;
            $aUserProcStat[$iUid]['jiff'] += $iJiff;
        }

        ksort($aUserProcStat);

        $aResult = array();
        foreach ( $aUserProcStat as $iUid => $aData )
        {
            $aUserInfo = posix_getpwuid($iUid);
            $sUser = ( empty($aUserInfo['name']) ) ? $iUid : $aUserInfo['name'];
            $aResult[ $sUser ] = $aData;
        }

        return $aResult;
    }

    private function _getProcInfo( $iProcId )
    {
        $aResult = array();

        $sProcStatFile =   $this->procPath.'/'.$iProcId.'/stat';
        $sProcStatusFile = $this->procPath.'/'.$iProcId.'/status';

        if (  is_readable($sProcStatFile)
           && is_readable($sProcStatusFile)
           )
        {
            $lines = file($sProcStatFile);
            $sStatLine = reset( $lines );
            $aStatFields = preg_split('|\s+|',$sStatLine);
            $aStatusFields = array();

            $lines = file( $sProcStatusFile );
            foreach ( $lines as $line )
            {
                $aMatch = array();
                if ( preg_match('/^(\w+):\s*(.+)$/',$line,$aMatch) )
                {
                    // Info about the status fields:
                    // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
                    // search for "/proc/[pid]/status"
                    $aStatusFields[ $aMatch[1] ] = $aMatch[2];
                }
            }

            $aUids = preg_split( '/\s+/', $aStatusFields['Uid'] );
            $iUid = ( empty($aUids[1]) ) ? fileowner($sProcStatFile) : $aUids[1];

            // For info about the fields:
            // http://www.kernel.org/doc/man-pages/online/pages/man5/proc.5.html
            // search for "/proc/[pid]/stat"

            $sName = $aStatFields[1];
            $sName = trim( $sName, '()-' );
            $iParentPid = $aStatFields[3];

            $this->statData[$iProcId]['iPid'] = $iProcId;
            $this->statData[$iProcId]['sName'] = $sName;
            $this->statData[$iProcId]['iParent'] = $iParentPid;
            if ( !empty($iParentPid) )
            {
                $this->statData[$iParentPid]['aChildPids'][] = $iProcId;
            }

            $this->statData[$iProcId]['iUid'] = $iUid;

            $this->statData[$iProcId]['iThisJiff'] = 0;
            $this->statData[$iProcId]['iThisJiff'] += $aStatFields[13]; // utime = user mode
            $this->statData[$iProcId]['iThisJiff'] += $aStatFields[14]; // stime = kernel mode

            $this->statData[$iProcId]['iChildJiff'] = 0;
            $this->statData[$iProcId]['iChildJiff'] += $aStatFields[15]; // cutime = children user mode
            $this->statData[$iProcId]['iChildJiff'] += $aStatFields[16]; // cstime = cnildren kernel mode

            $this->totalData['procs']++;
            if ( 1 == $aStatFields[3] ) // This process is started directly under the 'init' process
            {
                $this->totalData['jiff'] += $this->statData[$iProcId]['iThisJiff'];
                $this->totalData['jiff'] += $this->statData[$iProcId]['iChildJiff'];
            }

            $aResult = $this->statData[$iProcId];
        }
        return $aResult;
    }
}
