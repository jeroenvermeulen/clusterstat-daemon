<?php

require 'Adoy_FastCGI_Client.php'; // from: https://github.com/adoy/PHP-FastCGI-Client/

class PhpFpmStats {
    protected $sockets = array( 'scooters' => 'unix:///var/run/php-fpm-scooters.sock',
                                'israline' => 'tcp://86.109.17.219:9000' );
    protected $statusPath = '/jv_fpmstatus';
    protected $requestParams = array();
    protected $clients = array();
    protected $database     = null;
    protected $sqliteFile   = 'phpfpm_stats.sqlite';

    public function __construct() {
        $this->requestParams = array(
            'REQUEST_METHOD'    => 'GET',
            'SCRIPT_FILENAME'   => $this->statusPath,
            'SCRIPT_NAME'       => $this->statusPath,
            'QUERY_STRING'      => 'full&json',
        );
        foreach( $this->sockets as $name => $socket ) {
            $this->clients[ $name ] = new Adoy\FastCGI\Client( $socket, -1 );
            $this->clients[ $name ]->setPersistentSocket( true );
            $this->clientKeys[] = $name;
        }
    }

    public function getStats() {
        $result = '';
        foreach( $this->clients as $name => &$nil ) {
            $response = $this->clients[ $name ]->request($this->requestParams, false);
            if ( preg_match("|\n({.+})$|s", $response, $matches) ) {
                $data = json_decode( $matches[1], true );
                if ( !is_null($data) && !empty($data['pool'])
                     && !empty($data['processes']) && is_array($data['processes']) ) {
                    // $data['pool']
                    foreach ( $data['processes'] as $procKey => &$nil ) {
                        if ( 'Idle' == $data['processes'][$procKey]['state'] ) {
                            $result .= "url: ".$data['processes'][$procKey]['request uri']."\n";
                            $result .= "cpu: ".$data['processes'][$procKey]['last request cpu']."\n";
                            $result .= "mem: ".$data['processes'][$procKey]['last request memory']."\n";
                            $result .= "time: ".$data['processes'][$procKey]['request duration']."\n";
                            $result .= "\n";
                        }
                    }
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

}