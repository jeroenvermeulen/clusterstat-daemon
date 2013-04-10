<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
	<meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
	<title>ClusterStatDaemon</title>
	<script src="jquery-1.8.2.min.js"></script>
    <script type="text/javascript" src="highcharts.js"></script>
    <script type="text/javascript" src="highcharts_exporting.js"></script>
    <script type="text/javascript" src="procstats.js"></script>
    <script type="text/javascript">
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
	</script>
	<style type="text/css">
		body {
			margin:0px;
			padding: 0px;
			font-family: Verdana, Geneva, sans-serif;
			font-size: 14px;
		}
		#header {
			width: inherit;
			height: 60px;
			padding-left: 5px;
			background: #333 url(nedstars_asterisk.png) no-repeat 12px 5px;
			line-height: 60px;
			text-indent: 60px;
			overflow: hidden;
			color: #ffffff;
			font-size: 25px;
		}
		#runtime {
			width: inherit;
			height: auto;
			padding: 3px 15px 6px 15px;
			background: #1c0a49;
			color: #ffffff;
			text-align: left;
			font-size: 14px;
			margin-bottom: 10px;
		}
		#runtime span {
			font-weight: bold;
			padding-right: 30px;
		}
		#subheader {
			width: inherit;
			height: auto;
			padding: 10px 15px 15px 15px;
			background: #c5c5c5;
		}
		#subheader td {
			vertical-align: top;
		}
		#subheader td.label {
			width: 200px;
			font-weight: bold;
		}
		#contents {
			margin: 4px 15px 0px 15px;
		}
	</style>
</head>
<body>
	<div id="header">ClusterStatDaemon</div>
	<div id="subheader">
		This process is responsible for collecting cluster application server customer metrics
	</div>
	<div id="runtime">
		Main Memory Usage: <span id="main_memory_usage">{$runtimestats.memory_usage}</span>
		Uptime: <span id="main_uptime">{$runtimestats.uptime}</span>
		Server Load: <span id="main_load">{$runtimestats.load}</span>
	</div>
	<div id="contents">
        <div id="container" style="min-width: 400px; height: 300px; margin: 0 auto"></div>

        <table border="1" style="border-collapse: collapse; font-size: 11px;" cellpadding="2">
            <tr>
                <th>user</th>
                <th>jiff/sec</th>
                <th>counter</th>
            </tr>
        {foreach $procstats as $user=>$item}
            <tr id="templateRow">
                <td>{$user}</td>
                <td id="{$user}_jiff" style="text-align: right">-</td>
                <td id="{$user}_counter" style="text-align: right">-</td>
            </tr>
        {/foreach}
            <table>

	</div>
    <p><br /><a href="/procstats_detail_html" target="_blank">Details about processes</a></p>
</body>
</html>
