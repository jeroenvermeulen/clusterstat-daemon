<?php
/**
 * Cluster statistics aggragation
 *
 * @category    Statistics
 * @package     ClusterStatisticsDaemon
 * @author      Bas Peters <bas.peters@nedstars.nl>
 */

class ClusterStats {
    private $_startTime;
    
    /**
     * Class constructor
     *
     * @return void
     */
    public function __construct() {
        $this->_startTime = time();
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
        $template = new HtmlTemplate('index.tpl');
        $template->setVar('runtimestats', $this->_collectRuntimeStats());
        return $template->parse();
    }
    
    /**
     * Returns a JSON array of runtime information about the workermanger and its host system
     * @return array Runtime information array
     */
    public function getRuntimeStats($path, $queryString) {
        return json_encode($this->_collectRuntimeStats());
    }
    
    private function _collectRuntimeStats() {
        $loadArray = function_exists('sys_getloadavg') ? sys_getloadavg() : array(-1,-1,-1);
        return array(
            'memory_usage' => HtmlTemplate::prettyPrintMemorySize(memory_get_usage(true)),
            'uptime' => HtmlTemplate::prettyPrintTimestamp(time()-$this->_startTime),
            'load' => round($loadArray[0], 2)
        );
    }
    
    
}