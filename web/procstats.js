/*
 procstats.js - Real-time updating process statistics
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
//                         'TOTAL' != proc
                           'TOTAL' == proc
                         && 'undefined' != typeof procStats[ user ][proc]
                         && 'undefined' != typeof procStats[ user ][proc]['counter']
//                         &&  2000 < procStats[user][proc]['counter']
                       ) {
                        userProcs[userProcNr] = { user: user, proc: proc };
                        var val               = procStats[ user ][proc]['jiff'];
                        //var name              = user+' - '+proc;
                        var name              = user;
                        series[userProcNr]    = { name: name, data: [{ x: nowTimeMilli, y: val }] };
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

                    jQuery('#'+user+'_jiff').html( data[ user ][proc]['jiff'] );
                    jQuery('#'+user+'_counter').html( data[ user ][proc]['counter'] );
                }
            };
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