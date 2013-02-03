<!DOCTYPE HTML>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Jiffies per User</title>

    <script type="text/javascript" src="jquery-1.8.2.min.js"></script>
    <script type="text/javascript" src="highcharts.js"></script>
    <script type="text/javascript" src="highcharts_exporting.js"></script>

    <script type="text/javascript">

        $(function () {

            var users = [];
            var userCount = 0;
            var chart;
            var lastUpdate = 0;

            function getMilliTime() {
                var now = new Date()
                return ( 1000 * now.getTime() ) + now.getMilliseconds();
            }


            function updateChart() {

                jQuery.ajax({
                    dataType: "json",
                    url: 'procstats_json',
                    success: function(data) {
                        if ( 'undefined' != typeof data ) {
                            var time = (new Date()).getTime();
                            var shift =  ( 50 < chart.series[0].data.length );

                            for ( var nr=0; nr < userCount; nr++ ) {
                                var user = users[nr];
                                if ( 'undefined' != typeof data[user]
                                     && 'undefined' != typeof data[user]['TOTAL']
                                     && 'undefined' != typeof data[user]['TOTAL']['jiff'] ) {
                                    var val = data[ user ]['TOTAL']['jiff'];
                                    chart.series[nr].addPoint([time, val], true, shift);
                                }
                            };

                            var nowMilli = getMilliTime();
                            var spent = nowMilli - lastUpdate;
                            var sleep = Math.max( 1, 1000 - spent );
                            lastUpdate = nowMilli;
                            window.setTimeout( updateChart, sleep );
                        }
                    },
                    error: function() {
                        window.setTimeout( updateChart, 1000 );
                    }
                });
            }

            $(document).ready(function() {

                var procStats = {$procstats};
                var jiffies = [];

                var time = (new Date()).getTime();
                var userNr = 0;
                for ( var key in procStats ) {
                    if ( 'TOTAL' != key ) {
                        if ( 2000 < procStats[ key ]['TOTAL']['counter'] ) {
                            users.push( key );
                            var val = procStats[ key ]['TOTAL']['jiff'];
                            obj = { name: key, data: [{ x: time, y: val }] };
                            jiffies[userNr] = obj;
                            userNr++;
                        }
                    }
                }
                userCount = userNr;

                Highcharts.setOptions({
                    global: {
                        useUTC: false
                    }
                });

                chart = new Highcharts.Chart({
                    chart: {
                        renderTo: 'container',
                        type: 'spline',
                        marginRight: 130,
                        events: {
                            load: function() {
                                lastUpdate = getMilliTime();
                                window.setTimeout( updateChart, 100 );
                            }
                        }
                    },
                    title: {
                        text: 'Processor Usage'
                    },
                    xAxis: {
                        type: 'datetime',
                        tickPixelInterval: 150
                    },
                    yAxis: {
                        min: 0,
                        title: {
                            text: 'Jiffies'
                        },
                        plotLines: [{
                            value: 0,
                            width: 1,
                            color: '#808080'
                        }]
                    },
                    tooltip: {
                        formatter: function() {
                            return '<b>'+ this.series.name +'</b><br/>'+
                                    Highcharts.dateFormat('%Y-%m-%d %H:%M:%S', this.x) +'<br/>'+
                                    'Jiffies: ' + Highcharts.numberFormat(this.y, 2);
                        }
                    },
                    legend: {
                        layout: 'vertical',
                        align: 'right',
                        verticalAlign: 'top',
                        x: -10,
                        y: 100,
                        borderWidth: 0
                    },
                    exporting: {
                        enabled: false
                    },
                    plotOptions: {
                        spline: {
                            lineWidth: 3,
                            states: {
                                hover: {
                                    lineWidth: 4
                                }
                            },
                            marker: {
                                enabled: false,
                                states: {
                                    hover: {
                                        enabled: true,
                                        symbol: 'circle',
                                        radius: 5,
                                        lineWidth: 1
                                    }
                                }
                            }
                        }
                    },
                    series: jiffies
                });
            });
        });

    </script>
</head>
<body>

<div id="container" style="min-width: 400px; height: 400px; margin: 0 auto"></div>

</body>
</html>
