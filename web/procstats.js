jQuery(function () {

    var userProcs = [];
    var userProcCount = 0;
    var chart;
    var lastUpdate = 0;

    function initialData(procStats) {
        var series = [];

        var nowTimeMilli = (new Date()).getTime();
        var userProcNr = 0;
        for ( var user in procStats ) {
            if ( 'TOTAL' != user ) {
                for ( var proc in procStats[ user ] )
                {
                    if (
//                         (    ('TOTAL' != proc && 'root' != user)
//                           || ('TOTAL' == proc && 'root' == user)
//                         )
                            'TOTAL' != proc
                         && 'undefined' != typeof procStats[ user ][proc]
                         && 'undefined' != typeof procStats[ user ][proc]['counter']
                         &&  2000 < procStats[user][proc]['counter']
                       ) {
                        userProcs[userProcNr] = { user: user, proc: proc };
                        var val               = procStats[ user ][proc]['jiff'];
                        series[userProcNr]    = { name: user+' - '+proc, data: [{ x: nowTimeMilli, y: val }] };
                        userProcNr++;
                    }
                }
            }
        }
        userProcCount = userProcNr;

        Highcharts.setOptions({
            global: {
                useUTC: false
            }
        });

        chart = new Highcharts.Chart({
            chart: {
                renderTo: 'container',
                type: 'spline',
                marginRight: 220,
                events: {
                    load: function() {
                        lastUpdate = nowTimeMilli;
                        window.setTimeout( updateChart, 1000 );
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
                y: -10,
                borderWidth: 0
            },
            exporting: {
                enabled: false
            },
            plotOptions: {
                spline: {
                    lineWidth: 2,
                    states: {
                        hover: {
                            lineWidth: 3
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
            series: series
        });
    }

    function updateChart() {
        jQuery.ajax({
            url: 'procstats_json',
            timeout: 15000,
            dataType: "json",
            success: updateCallBack,
            error: function(req,stat,err) {
                window.setTimeout( updateChart, 1000 );
            }
        });
    }

    function updateCallBack( data, stat, req )
    {
        if ( 'undefined' == typeof data ) {
            window.setTimeout( updateChart, 1000 );
        }
        else {
            var nowTimeMilli = (new Date()).getTime();
            var shift =  ( 50 < chart.series[0].data.length );

            for ( var nr=0; nr < userProcCount; nr++ ) {
                var user = userProcs[nr]['user'];
                var proc = userProcs[nr]['proc'];
                if (   'undefined' != typeof data[user]
                    && 'undefined' != typeof data[user][proc]
                    && 'undefined' != typeof data[user][proc]['jiff'] ) {
                    var val = data[ user ][proc]['jiff'];
                    chart.series[nr].addPoint([nowTimeMilli, val], true, shift);
                }
            };
            // TODO: Check if needed to get this "spent" trick working.
            //       Currently if enabled we end up with too mutch updates
//            var spent = nowTimeMilli - lastUpdate;
//            var sleep = Math.max( 1, 1000 - spent );
//            lastUpdate = nowTimeMilli;
//            window.setTimeout( updateChart, spent );
            window.setTimeout( updateChart, 1000 );
        }
    }

    $(document).ready(function() {
        jQuery.ajax({
            async: false,
            dataType: "json",
            url: 'procstats_json',
            success: initialData,
            error: function() {
                window.alert('Error getting initial data.');
            }
        });
    });

});