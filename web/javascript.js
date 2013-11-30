/*
 javascript.js - ClusterStats main JavaScript File
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
$.ajaxSetup({ cache: false });
/**
 * Performs a JSON data update and refreshes the UI inline with runtime statistics
 */
function updateRuntimeStats() {
    // perform JSON request to get updated statistics
    $.getJSON('runtimestats.js', function(data) {
        $('#main_memory_usage').html(data.memory_usage);
        $('#main_uptime').html(data.uptime);
        $('#main_load').html(data.load);
        $('#main_dispatchers').html(data.dispatchers);
    });

    // schedule a new timeslot to update
    window.setTimeout(updateRuntimeStats, 2000);
}

/**
 * Run the timed update to update the UI with new statistics from the backend
 */
$(document).ready(function(){
    updateRuntimeStats();
});