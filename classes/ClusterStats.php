<?php
/*
ClusterStats.php - Cluster statistics aggragation
Copyright (C) 2013  Bas Peters <bas@baspeters.com> & Jeroen Vermeulen <info@jeroenvermeulen.eu>

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
 * Cluster statistics aggragation
 *
 * @category    Statistics
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
 * @author      Jeroen Vermeulen <info@jeroenvermeulen.eu>
 */

class ClusterStats {
    private $_startTime;
    /** @var ProcStats */
    private $_procStats;

    /**
     * Class constructor
     *
     * @return ClusterStats
     */
    public function __construct() {
        $this->_startTime = time();
    }

    public function setProcStats( ProcStats $procStats )
    {
        $this->_procStats = $procStats;
    }

    /**
     * Renders the homepage of the web admin backend
     *
     * @param String $path The path of the http request
     * @param String $queryString The querystring of the http request
     *
     * @return String Html contents of the homepage
     */
    public function homepage($path, $queryString) {
        $template = new HtmlTemplate('index.html');
        $template->setVar('runtimestats', $this->_collectRuntimeStats());
        $template->setVar('procstats', $this->_procStats->getProcStats($path, $queryString) );
        return $template->parse();
    }

    /**
     * Returns a JSON array of runtime information about the worker manager and its host system
     *
     * @var $path        - not used
     * @var $queryString - not used
     *
     * @return string - JSON encoded runtime information array
     */
    public function getRuntimeStats( /** @noinspection PhpUnusedParameterInspection */ $path, $queryString) {
        return json_encode( $this->_collectRuntimeStats() );
    }

    /**
     * Returns an array of runtime information about the worker manager and its host system
     *
     * @return array - Runtime information array
     */
    protected function _collectRuntimeStats() {
        $loadArray = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : array(-1,-1,-1);
        return array(
            'memory_usage' => HtmlTemplate::prettyPrintMemorySize(memory_get_usage(true)),
            'uptime' => HtmlTemplate::prettyPrintTimestamp(time()-$this->_startTime),
            'load' => round($loadArray[0], 2),
        );
    }

}
