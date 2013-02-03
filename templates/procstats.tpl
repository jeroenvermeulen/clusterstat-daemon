<!DOCTYPE HTML>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Jiffies per User</title>

    <script type="text/javascript" src="jquery-1.8.2.min.js"></script>
    <script type="text/javascript" src="highcharts.js"></script>
    <script type="text/javascript" src="highcharts_exporting.js"></script>

    <script type="text/javascript">

        jQuery(function () {

            var users = [];
            var chart;

            function updateChart() {
                jQuery.getJSON('procstats_json', function(data) {
                    var jiffies = [];
                    users.forEach(function(key) {
                        if ( 'TOTAL' != key ) {
                            jiffies.push( data[ key ]['TOTAL']['jiff'] );
                        }
                    });
                    chart.series[0].setData(jiffies);
                    chart.redraw();
                });
                window.setTimeout( updateChart, 1000 );
            }

            jQuery(document).ready(function() {

                var procStats = {$procstats};
                var jiffies = [];
                for ( var key in procStats ) {
                    if ( 'TOTAL' != key ) {
                        users.push( key );
                        jiffies.push( procStats[ key ]['TOTAL']['jiff'] );
                    }
                }

                chart = new Highcharts.Chart({
                    chart: {
                        renderTo: 'container',
                        type: 'column',
                        margin: [ 50, 50, 100, 80]
                    },
                    title: {
                        text: 'Jiffies per user'
                    },
                    xAxis: {
                        categories: users,
                        labels: {
                            rotation: -90,
                            align: 'right',
                            style: {
                                fontSize: '13px',
                                fontFamily: 'Verdana, sans-serif'
                            }
                        }
                    },
                    yAxis: {
                        min: 0,
                        max: 1000,
                        title: {
                            text: 'Jiffies'
                        }
                    },
                    legend: {
                        enabled: false
                    },
                    tooltip: {
                        formatter: function() {
                            return '<b>'+ this.x +'</b><br/>'+
                                    'Jiffies: '+ Highcharts.numberFormat(this.y, 1);
                        }
                    },
                    series: [{
                        name: 'Jiffies',
                        data: jiffies,
                        dataLabels: {
                            enabled: true,
                            rotation: 0,
                            color: '#FFFFFF',
                            align: 'center',
                            x: 0,
                            y: 20,
                            style: {
                                fontSize: '10px',
                                fontFamily: 'Verdana, sans-serif'
                            }
                        }
                    }]
                });

                window.setTimeout( updateChart, 1000 );
            });

        });
    </script>
</head>
<body>

<div id="container" style="min-width: 400px; height: 400px; margin: 0 auto"></div>

</body>
</html>
