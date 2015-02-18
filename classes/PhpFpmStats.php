<?php

require 'Adoy_FastCGI_Client.php'; // from: https://github.com/adoy/PHP-FastCGI-Client/

class PhpFpmStats {
    protected $sockets       = array(
        'demo' => 'unix:///var/run/php-fpm-demo.sock',
        'israline' => 'tcp://86.109.17.219:9000',
        'kcperf' => 'tcp://86.109.17.204:9000',
        'maghopro' => 'unix:///var/run/php-fpm-maghopro.sock',
        'narwalla' => 'unix:///var/run/php-fpm-narwalla.sock',
        'scooters' => 'unix:///var/run/php-fpm-scooters.sock',
        'semtaf' => 'tcp://86.109.17.202:9000',
        'sky' => 'unix:///var/run/php-fpm-sky.sock',
        'verzin' => 'tcp://86.109.17.210:9000',
        'vloprwww' => 'unix:///var/run/php-fpm-vloprwww.sock' );
    protected $statusPath    = '/jv_fpmstatus';
    protected $requestParams = array();
    protected $clients       = array();
    protected $previousProcesses = array();
    /** @var PDO */
    protected $database      = null;
    protected $sqliteFile    = 'phpfpm_stats.sqlite';
    protected $workdir       = null;
    /** @var PDOStatement */
    protected $updateStatsQuery = null;
    /** @var PDOStatement */
    protected $insertStatsQuery = null;

    public function __construct() {
        $this->workDir = dirname( dirname(__FILE__) );
        $this->requestParams = array(
            'REQUEST_METHOD'    => 'GET',
            'SCRIPT_FILENAME'   => $this->statusPath,
            'SCRIPT_NAME'       => $this->statusPath,
            'QUERY_STRING'      => 'full&json',
        );
        foreach( $this->sockets as $name => $socket ) {
            $this->clients[$name] = new Adoy\FastCGI\Client( $socket, -1 );
            $this->clients[$name]->setPersistentSocket( true );
            $this->previousProcesses[$name] = array();
        }
        $database = $this->getDatabase();
        $this->updateStatsQuery = $database->prepare('UPDATE "stats" SET "count"="count"+1, "time"="time"+:time,
                                                                         "cputime"="cputime"+:cputime, "mem"="mem"+:mem
                                                      WHERE "date"=:date AND "pool"=:pool AND "method"=:method
                                                            AND "url"=:url');
        if ( empty($this->updateStatsQuery) ) {
            throw new Exception('Prepare of update statement failed.');
        }
        $this->insertStatsQuery = $database->prepare('INSERT INTO "stats"
                                                         ("date","pool","method","url","count","time","cputime","mem")
                                                      VALUES
                                                         (:date,:pool,:method,:url,1,:time,:cputime,:mem)');
        if ( empty($this->insertStatsQuery) ) {
            throw new Exception('Prepare of insert statement failed.');
        }
    }

    public function getStats() {
        return 'TODO';
    }

    public function timerStats() {
        $result = '';
        $url = '';
        $pool = '';
        $method = '';
        $date = date('Y-m-d');
        $time = 0;
        $cputime = 0;
        $mem = 0;
        $this->updateStatsQuery->bindParam( ':pool', $pool );
        $this->updateStatsQuery->bindParam( ':method', $method );
        $this->updateStatsQuery->bindParam( ':url',  $url );
        $this->updateStatsQuery->bindParam( ':date', $date );
        $this->updateStatsQuery->bindParam( ':time', $time );
        $this->updateStatsQuery->bindParam( ':cputime', $cputime );
        $this->updateStatsQuery->bindParam( ':mem', $mem );
        $this->insertStatsQuery->bindParam( ':pool', $pool );
        $this->insertStatsQuery->bindParam( ':method', $method );
        $this->insertStatsQuery->bindParam( ':url',  $url );
        $this->insertStatsQuery->bindParam( ':date', $date );
        $this->insertStatsQuery->bindParam( ':time', $time );
        $this->insertStatsQuery->bindParam( ':cputime', $cputime );
        $this->insertStatsQuery->bindParam( ':mem', $mem );
        foreach( $this->clients as $name => &$nil ) {
            try {
                $response = $this->clients[ $name ]->request($this->requestParams, false);
            } catch (Exception $e) {
                // Socket may be broken, reconnect
                $this->clients[$name] = new Adoy\FastCGI\Client( $this->sockets[$name], -1 );
                $response = $this->clients[ $name ]->request($this->requestParams, false);
            }

            if ( preg_match("|\n({.+})$|s", $response, $matches) ) {
                $data = json_decode( $matches[1], true );
                if ( !is_null($data) && !empty($data['pool'])
                     && !empty($data['processes']) && is_array($data['processes']) ) {
                    $pool = $data['pool'];
                    $currentProcesses = array();
                    foreach ( $data['processes'] as $procNr => &$nil ) {
                        if ( 'Idle' == $data['processes'][$procNr]['state']
                             && !empty($data['processes'][$procNr]['last request cpu']) ) {
                            $procKey = $data[ 'processes' ][ $procNr ][ 'pid' ] . '-'
                                       . $data[ 'processes' ][ $procNr ][ 'requests' ];
                            $currentProcesses[$procKey] = 1;
                            if ( empty($this->previousProcesses[$name][$procKey]) ) {
                                $method  = $data['processes'][$procNr]['request method'];
                                $url     = substr($data['processes'][$procNr]['request uri'],0,255);
                                $time    = $data['processes'][$procNr]['request duration'];
                                $cputime = round( $time * $data['processes'][$procNr]['last request cpu'] / 100 );
                                $mem     = $data['processes'][$procNr]['last request memory'];
                                $this->updateStatsQuery->execute();
                                if ( 0 == $this->updateStatsQuery->rowCount() ) {
                                    $result .= "inserting.\n";
                                    $this->insertStatsQuery->execute();
                                }
                            }
                        }
                    }
                    $this->previousProcesses[$name] = $currentProcesses;
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