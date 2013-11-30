<?php
/*
HtmlTemplate.php - Html template parser
Copyright (C) 2013  Bas Peters <bas@baspeters.com>

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
 * Html template parser
 *
 * @category Web server
 * @package  ClusterStatsDaemon
 * @author   Bas Peters <bas@baspeters.com>
 */
class HtmlTemplate {
    /**
     * @var String The html contents of the template
     */
    private $_html;

    /**
     * @var Array Mixed template variables
     */
    private $_variables = array();

    /**
     * Class constructor
     *
     * @param String $template  Filename of the template
     * @param Array  $variables Associative array containing template variables
     * @throws Exception
     *
     * @return HtmlTemplate
     */
    public function __construct($template, $variables = array()) {
        $fileName = APP_ROOT . 'templates' . DIRECTORY_SEPARATOR . $template;
        if (!is_readable($fileName)) {
            throw new Exception("Template file '{$template}' does not exist or is not accessible");
        }
        $this->_html = file_get_contents($fileName);
        $this->_variables = $variables;
    }

    /**
     * Parses the current template file and returns the html template as a string
     *
     * @return String The html template string
     */
    public function parse() {
        $this->_processTemplate();
        return $this->_html;
    }

    /**
     * Parses the current template file and print the template to the standard output
     *
     * @return void
     */
    public function display() {
        $this->_processTemplate();
        echo $this->_html;
    }

    /**
     * Sets a template variable
     *
     * @param String $key The variable key
     * @param String $value The value of the variable
     *
     * @return void
     */
    public function setVar($key, $value) {
        $this->_variables[$key] = $value;
    }

    /**
     * Pretty prints an integer into a readable memory size string
     *
     * @param int $size Size in bytes
     *
     * @return string Pretty printed memory size
     */
    public static function prettyPrintMemorySize($size) {
        if ( $size == 0 ) return '-';
        $unit = array('B','Kb','Mb','Gb','Tb','Pb');
        $i = intval( floor( log( $size, 1024 ) ) );
        return @round( $size / pow( 1024, $i ), 2 ) . ' ' . $unit[$i];
    }

    /**
     * Pretty prints an integer into a readable time-elapsed string
     *
     * @param int $seconds Number of seconds
     *
     * @return string Pretty printed time-elapsed string
     */
    public static function prettyPrintTimestamp($seconds) {
        if($seconds==0) return '-';
        $periods = array (
            'years' => 31556926,
            'months' => 2629743,
            'days' => 86400,
            'hours' => 3600,
            'mins' => 60,
            'secs' => 1
        );

        $seconds = (float) $seconds;
        $segments = array();
        foreach ($periods as $period => $value) {
            $count = floor($seconds / $value);
            if ($count == 0) {
                continue;
            }
            $segments[$period] = $count;
            $seconds = $seconds % $value;
        }

        $result = array();
        foreach ($segments as $key => $value) {
            $segment = $value . substr($key,0,1);
            $result[] = $segment;
        }

        return implode(' ', $result);
    }

    /**
     * Processes an html template
     *
     */
    private function _processTemplate() {
        $loopPrevention=0;
        do {
            $processed = $this->_processTemplateForeach($this->_html, $this->_variables);
        } while($processed>0 && ++$loopPrevention<100);
        $this->_processTemplateVariables($this->_html, $this->_variables);
    }

    /**
     * Replace template variables in html
     *
     * @return void;
     */
    private function _processTemplateVariables() {
        preg_match_all('/(?P<template>{\$(?P<var>\w+)\.?(?P<key>\w+)?})/', $this->_html, $varMatches, PREG_SET_ORDER);
        $replaceVarArray = array();

        foreach($varMatches as $tplVar) {
            if(!(isset($tplVar['template']) && isset($tplVar['var']))) {
                // this match is incomplete, skip
                continue;
            } elseif(!isset($this->_variables[$tplVar['var']])) {
                // there was a complete match, but the variable could not be found, mark as missing
                $replaceVarArray['/'.preg_quote($tplVar['template']).'/']='<b style="color: red">'.$tplVar['template'].'</b>';
                continue;
            }

            if(isset($tplVar['key'])) {
                // the variable is separated by a dot to indicate a subarray key value
                $replaceTo = isset($this->_variables[$tplVar['var']][$tplVar['key']]) ? $this->_variables[$tplVar['var']][$tplVar['key']] : '';
                $replaceVarArray['/'.preg_quote($tplVar['template']).'/']=$replaceTo;
            } else {
                // the variable is a normal variable and available to be replaced
                $replaceTo = isset($this->_variables[$tplVar['var']]) ? $this->_variables[$tplVar['var']] : '';
                $replaceVarArray['/'.preg_quote($tplVar['template']).'/']=$replaceTo;
            }
        }
        // replace the found variables with the constructed find/replace regex
        $this->_html = preg_replace(array_keys($replaceVarArray), array_values($replaceVarArray), $this->_html);
    }

    /**
     * Processes a passthrough html string to parse foreach blocks and replace variable placeholders
     *
     * @return int Number of replacements made
     */
    private function _processTemplateForeach() {
        // get the first foreach block from the pass through html variable
        $count = preg_match('/{foreach\s+\$(?P<list>\w+)\s+as\s+(\$(?P<key>\w+)\s*=>\s*)?\$(?P<item>\w+)\s*}(?P<contents>.*?){\/foreach}/s',$this->_html, $feMatches);
        $output = '';

        if (isset($feMatches['list']) && isset($feMatches['item']) && isset($feMatches['contents'])
            && isset($this->_variables[$feMatches['list']]) && is_array($this->_variables[$feMatches['list']]))
        {
            // a valid foreach block was found
            $list = $feMatches['list'];
            $item = $feMatches['item'];
            $contents = trim($feMatches['contents']);
            $key = isset($feMatches['key']) ? $feMatches['key'] : null;

            // proceed to find all variable placeholders
            preg_match_all('/(?P<template>{\$(?P<var>\w+)\.?(?P<key>\w+)?})/', $contents, $varMatches, PREG_SET_ORDER);
            foreach($this->_variables[$list] as $varKey=>$varItem) {
                $replaceVarArray = array();
                foreach($varMatches as $tplVar) {
                    $replaceTo = null;
                    if(!(isset($tplVar['template']) && isset($tplVar['var']))) {
                        /// this match is incomplete, skip
                        continue;
                    }
                    if($tplVar['var']==$key) {
                        // the variable is actually the key of the foreach
                        $replaceTo = $varKey;
                    } elseif($tplVar['var']==$item && !isset($tplVar['key'])) {
                        // the variable is the literal value of the foreach
                        $replaceTo = $varItem;
                    } elseif(isset($tplVar['key'])) {
                        // the variable is separated by a dot to indicate a key value pair in the looped array
                        if(!isset($this->_variables[$list][$varKey][$tplVar['key']])) {
                            // the variable placeholder could not be resolved to an actual value
                            continue;
                        }
                        $replaceTo = $this->_variables[$list][$varKey][$tplVar['key']];
                    } else {
                        // the variable is a normal variable and available to be replaced
                        if(!isset($this->_variables[$tplVar['var']])) {
                            // the variable placeholder could not be resolved to an actual value
                            continue;
                        }
                        $replaceTo = $this->_variables[$tplVar['var']];
                    }
                    if(is_array($replaceTo) || is_object($replaceTo) || is_resource($replaceTo)) {
                        Log::info('Template variable ' . $tplVar['template'] . ' contains invalid content and could not be replaced');
                    } else {
                        $replaceVarArray['/'.preg_quote($tplVar['template']).'/']=$replaceTo;
                    }
                }
                // replace the found variables with the constructed find/replace regex
                $iterationOutput = preg_replace(array_keys($replaceVarArray), array_values($replaceVarArray), $contents);
                $output .= $iterationOutput;
            }
        }
        // replace the foreach block in the pass through html with the processed version
        $this->_html = preg_replace('/{foreach.*?\/foreach}/s', $output, $this->_html, 1);
        return $count;
    }
}