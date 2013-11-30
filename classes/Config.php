<?php
/*
Config.php - Simple configuration class
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
 * Simple configuration class
 *
 * @category    Config
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
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