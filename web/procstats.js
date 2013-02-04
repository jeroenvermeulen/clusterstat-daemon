$(function () {

    var users = [];
    var userCount = 0;
    var chart;
    var lastUpdate = 0;

    function getMilliTime() {
        var now = new Date()
        return ( 1000 * now.getTime() ) + now.getMilliseconds();
    }

    function initialData(procStats) {
        var series = [];

        var time = (new Date()).getTime();
        var userNr = 0;
        for ( var key in procStats ) {
            if ( 'TOTAL' != key ) {
                if ( 'undefined' != typeof procStats[ key ]['TOTAL']
                    && 'undefined' != typeof procStats[ key ]['TOTAL']['counter']
                    &&  2000 < procStats[ key ]['TOTAL']['counter'] ) {
                    users[userNr] = key;
                    var val = procStats[ key ]['TOTAL']['jiff'];
                    obj = { name: key, data: [{ x: time, y: val }] };
                    series[userNr] = obj;
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
                        window.setTimeout( updateChart, 500 );
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
            series: series
        });
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
        jQuery.ajax({
            dataType: "json",
            url: 'procstats_json',
            success: initialData,
            error: function() {
                window.alert('Error getting initial data.');
            }
        });
    });

});