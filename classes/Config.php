<?php
/**
 * Simple configuration class
 *
 * @category    Config
 * @package     ClusterStatisticsDaemon
 * @author      Bas Peters <bas.peters@nedstars.nl>
 */
class Config {
    /**
     * @var Array Variable containing all configuration items
     */
    private static $_items = array();
    
    /**
     * Gets a configuration item
     * 
     * @param String $key The configuration key
     * @return Mixed The value or null if the key does not exist
     */
    public static function get($key) {
        if(isset(self::$_items[$key])) {
            return self::$_items[$key];
        } else {
            return null;
        }
    }
    
    /**
     * Sets a configuration item
     * 
     * @param String $key The configuration key
     * @param Mixed $value The value to be set
     *
     * @return void
     */
    public static function set($key, $value) {
        self::$_items[$key] = $value;
    }
}