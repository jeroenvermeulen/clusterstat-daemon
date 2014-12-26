<?php

require 'Adoy_FastCGI_Client.php'; // from: https://github.com/adoy/PHP-FastCGI-Client/

class PhpFpmStats {
    protected $sockets       = array( 'scooters' => 'unix:///var/run/php-fpm-scooters.sock',
                                      'israline' => 'tcp://86.109.17.219:9000' );
    protected $statusPath    = '/jv_fpmstatus';
    protected $requestParams = array();
    protected $clients       = array();
    protected $previousProcesses = array();
    /** @var PDO */
    protected $database      = null;
    protected $sqliteFile    = 'phpfpm_stats.sqlite';
    protected $workdir       = null;
    /** @var PDOStatement */
    protected $selectUrlQuery = null;
    /** @var PDOStatement */
    protected $insertUrlQuery = null;

    public function __construct() {
        $this->workDir = dirname( dirname(__FILE__) );
        $this->requestParams = array(
            'REQUEST_METHOD'    => 'GET',
            'SCRIPT_FILENAME'   => $this->statusPath,
            'SCRIPT_NAME'       => $this->statusPath,
            'QUERY_STRING'      => 'full&json',
        );
        foreach( $this->sockets as $pool => $socket ) {
            $this->clients[$pool] = new Adoy\FastCGI\Client( $socket, -1 );
            $this->clients[$pool]->setPersistentSocket( true );
            $this->previousProcesses[$pool] = array();
        }
        $database = $this->getDatabase();
        $this->insertUrlQuery = $database->prepare('INSERT INTO "url" ("pool","url") VALUES (:pool,:url)');
        $this->selectUrlQuery = $database->prepare('SELECT "id" FROM "url" WHERE "pool"=:pool AND "url"=:url');
        $this->updateStatsQuery = $database->prepare('UPDATE "stats" SET "count"="count"+1, "time"="time"+:time,
                                                      "cputime"="cputime"+:cputime, "mem"="mem"+:mem
                                                      WHERE "date"=:date AND "url_id"=:url_id');
        $this->insertStatsQuery = $database->prepare('INSERT INTO "stats" ("date","url_id","count","time","cputime","mem")
                                                      VALUES (:date,:url_id,1,:time,:cputime,:mem)');

    }

    public function getStats() {
        $result = '';
        $url = '';
        $pool = '';
        $url_id = '';
        $date = date('Y-m-d');
        $time = 0;
        $cputime = 0;
        $mem = 0;
        $this->selectUrlQuery->bindParam( ':url',  $url );
        $this->selectUrlQuery->bindParam( ':pool', $pool );
        $this->insertUrlQuery->bindParam( ':url',  $url );
        $this->insertUrlQuery->bindParam( ':pool', $pool );
        $this->updateStatsQuery->bindParam( ':url_id',  $url_id );
        $this->updateStatsQuery->bindParam( ':date', $date );
        $this->updateStatsQuery->bindParam( ':time', $time );
        $this->updateStatsQuery->bindParam( ':cputime', $cputime );
        $this->updateStatsQuery->bindParam( ':mem', $mem );
        $this->insertStatsQuery->bindParam( ':url_id',  $url_id );
        $this->insertStatsQuery->bindParam( ':date', $date );
        $this->insertStatsQuery->bindParam( ':time', $time );
        $this->insertStatsQuery->bindParam( ':cputime', $cputime );
        $this->insertStatsQuery->bindParam( ':mem', $mem );
        foreach( $this->clients as $name => &$nil ) {
            $response = $this->clients[ $name ]->request($this->requestParams, false);
            if ( preg_match("|\n({.+})$|s", $response, $matches) ) {
                $data = json_decode( $matches[1], true );
                if ( !is_null($data) && !empty($data['pool'])
                     && !empty($data['processes']) && is_array($data['processes']) ) {
                    $pool = $data['pool'];
                    $currentProcesses = array();
                    foreach ( $data['processes'] as $procNr => &$nil ) {
                        if ( 'Idle' == $data['processes'][$procNr]['state'] ) {
                            $procKey = $data[ 'processes' ][ $procNr ][ 'pid' ] . '-'
                                       . $data[ 'processes' ][ $procNr ][ 'requests' ];
                            $currentProcesses[$procKey] = 1;
                            if ( empty($this->previousProcesses[$pool][$procKey]) ) {
                                $url = substr($data['processes'][$procNr]['request uri'],0,255);
                                $time = $data['processes'][$procNr]['request duration'];
                                $cputime = round( $time * $data['processes'][$procNr]['last request cpu'] / 100 );
                                $mem = $data['processes'][$procNr]['last request memory'];

                                $this->selectUrlQuery->execute();
                                $url_id = $this->selectUrlQuery->fetchColumn();
                                $result .= "url id: ".$url_id."\n";

                                if ( empty($url_id) ) {
                                    $this->insertUrlQuery->execute();
                                    $url_id = $this->database->lastInsertId();
                                    $result .= "inserted url id2: ".$url_id."\n";
                                }


                                $this->updateStatsQuery->execute();
                                if ( 0 == $this->updateStatsQuery->rowCount() ) {
                                    $result .= "inserting.\n";
                                    $this->insertStatsQuery->execute();
                                }

                                $result .= "pool: ".$pool."\n";
                                $result .= "meth: ".$data['processes'][$procNr]['request method']."\n";
                                $result .= "url: ".$url."\n";
                                $result .= "cpu: ".$cputime."\n";
                                $result .= "mem: ".$mem."\n";
                                $result .= "time: ".$time."\n";
                                $result .= "\n";
                            }
                        }
                    }
                    $this->previousProcesses[$pool] = $currentProcesses;
                }
            }
        }
        return $result;
    }

    /**
     * Get an SQLite database connection
     *
     * @throws Exception
     * @return PDO
     */
    protected function getDatabase()
    {
        if ( empty($this->database) )
        {
            $sqliteFile   = $this->workDir . '/' . $this->sqliteFile;
            $templateFile = $this->workDir.'/templates/'.$this->sqliteFile;
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
            $this->database = new PDO('sqlite:'.$sqliteFile);
            // Set errormode to exceptions
//            $this->database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            unset($sqliteFile);
            unset($templateFile);
        }
        return $this->database;
    }

    /**
     * Write process data to SQLite database that is used to detect and fixes
     * resets of counters.
     *
     * @param array $data - the row data to write
     */
    protected function _writeToDatabase( $data )
    {
        $database    = $this->getDatabase();
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

}