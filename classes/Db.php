<?php
/*
  Author: S.H.W. Peters
  Date: 2007-10-09
  Purpose: MySQL database abstraction class
*/

class Db {
	/**
	 * Class constants
	 */
	const RESULT_ASSOC = 0;
	const RESULT_NUMERIC = 1;
	const RESULT_KEY = 2;
	const RESULT_DOUBLE_KEY = 3;
	const RESULT_SIMPLE = 4;
	const RESULT_KEY_MULTI = 5;
	const RESULT_DOUBLE_KEY_MULTI = 6;

	const GET_MULTI_ROW = 0;
	const GET_SINGLE_ROW = 1;
	const GET_SINGLE_FIELD = 2;
	const GET_HANDLE = 3;
	
	public $lastQuery;
	public $rowsAffected = null;
	public $numRows = null;
	public $numQueries = 0;
	public $insertId = null;
	
	private $_database = null;
	private $_lastResult = null;
	
    // class destructor
    public function __destruct() {
        @mysql_close($this->_database);
    }
    
	// class constructor
	public function __construct($dbuser, $dbpassword, $dbhost, $dbname, $new = false, $charset = 'utf8' ) {
		$this->_database = @mysql_connect($dbhost,$dbuser,$dbpassword, $new);

		if (!$this->_database) {
			throw new Exception('Cannot establish a database connection', 1);
		}
        
        mysql_query("SET NAMES {$charset}", $this->_database);
        mysql_query("SET CHARACTER_SET_CONNECTION {$charset}", $this->_database);
        
		$this->select($dbname);
	}
	
	// select a (different) database from the datasource
	public function select($db) {
		if (!@mysql_select_db($db,$this->_database)) {
			throw new Exception("Error selecting new database '{$db}'", 2);
		}
	}
	
	// get data from a query and return it in an array
	public function getArray($sql, $return_type = Db::RESULT_ASSOC) {
		return $this->_getResults($sql, $return_type, Db::GET_MULTI_ROW);
	}

	// get data from a query and return it in an array
	public function getRow($sql, $return_type = Db::RESULT_ASSOC) {
		return $this->_getResults($sql, $return_type, Db::GET_SINGLE_ROW);
	}

	// get data from a query and return it in an array
	public function getValue($sql) {
		return $this->_getResults($sql, Db::RESULT_NUMERIC, Db::GET_SINGLE_FIELD);
	}

    // get data from a query and return it as a handle
    public function getHandle ($sql, $return_type = self::RESULT_ASSOC)
    {
        return $this->_getResults($sql, $return_type, self::GET_HANDLE);
    }
	
	public function query($sql) {
		// Make sure there are no white spaces in the query
		$sql = trim($sql); 
			
		// Initialise return value
		$returnVal = 0;

		// Store the last query
		$this->lastQuery = $sql;

		// Perform the query
		$this->result = @mysql_query($sql, $this->_database);
		$this->numQueries++;

		// If an error occured, throw the error
		if(mysql_error()) {
			throw new Exception(mysql_error($this->_database) . ' (' . mysql_errno($this->_database) . ')', 1);
		}

		$this->rowsAffected = null;
		$this->insertId = null;		
		$this->_lastResult = null;		
		
		// Query was an insert, delete, update, replace
		if(preg_match("/^(insert|delete|update|replace)\s+/i",$sql)) {
			$this->rowsAffected = mysql_affected_rows();
				
			// Take note of the insertId
			if ( preg_match("/^(insert|replace)\s+/i",$sql) ) {
				$this->insertId = mysql_insert_id($this->_database);
			}
				
			// Return number of rows affected
			$returnVal = $this->rowsAffected;
		} elseif (preg_match("/^(select)\s+/i",$sql)) {
			// Query was a select
				
			// Take note of column info	
			$i=0;
			while ($i < @mysql_num_fields($this->result))
			{
				$this->col_info[$i] = @mysql_fetch_field($this->result);
				$i++;
			}
				
			// Store the results of the query in an object array	
			$numRows=0;
			while ($row = @mysql_fetch_object($this->result)) {
				// Store results as an objects within main array
				$this->_lastResult[$numRows] = $row;
				$numRows++;
			}

			@mysql_free_result($this->result);

			// Log number of rows the query returned
			$this->numRows = $numRows;
				
			// Return number of rows selected
			$returnVal = $this->numRows;
		}

		return $returnVal;
	}
	
	// get an array of data from a query
	private function _getResults($sql, $return_type, $return_scope = Db::GET_MULTI_ROW) {
		// Store the last query
		$this->lastQuery = $sql;

		// Perform the query
		$this->result = @mysql_query($sql,$this->_database);
		$this->numQueries++;
		
		// If an error occured, throw the error
		if(mysql_error()) {
			throw new Exception(mysql_error($this->_database) . ' (' . mysql_errno($this->_database) . ')', 1);
		}
		
		$this->rowsAffected = null;
		$this->insertId = null;		
		$this->numRows = null;
		$this->_lastResult = null;

        if ($return_scope === self::GET_HANDLE) {
            return $this->result;
        }
		
		$result_type = ($return_type == Db::RESULT_ASSOC || $return_type == Db::RESULT_KEY_MULTI || $return_type == Db::RESULT_DOUBLE_KEY_MULTI ? MYSQL_ASSOC : MYSQL_NUM);
		
		$numRows=0;
		while ($row = mysql_fetch_array($this->result, $result_type)) {
			// Store results based on the return type
			switch( $return_type ) {
				case Db::RESULT_ASSOC:
				case Db::RESULT_NUMERIC:
					$this->_lastResult[] = $row;
					break;
				case Db::RESULT_KEY:
					$this->_lastResult[$row[0]] = $row[1];
					break;
				case Db::RESULT_DOUBLE_KEY:
					$this->_lastResult[$row[0]][$row[1]] = $row[2];
					break;
				case Db::RESULT_DOUBLE_KEY_MULTI:
					$key1 = array_shift($row);
					$key2 = array_shift($row);
					$this->_lastResult[$key1][$key2] = $row;
					break;
				case Db::RESULT_KEY_MULTI:
					$key = array_shift($row);
					$this->_lastResult[$key] = $row;
					break;
				case Db::RESULT_SIMPLE:
					$this->_lastResult[] = $row[0];
					break;
			}
			$numRows++;
			if($return_scope != Db::GET_MULTI_ROW) break;
		}
		
		// free the database resultset
		@mysql_free_result($this->result);
		$this->numRows = $numRows;
		
		// return the results in the correct scope
		switch($return_scope) {
			case Db::GET_MULTI_ROW:
				return $this->_lastResult;
			case Db::GET_SINGLE_ROW:
				return (isset($this->_lastResult[0]) ? $this->_lastResult[0] : null);
			case Db::GET_SINGLE_FIELD:
				return (isset($this->_lastResult[0][0]) ? $this->_lastResult[0][0] : null);
		}		
	}	
}

?>