ClusterStat Daemon
==================

Daemon for gathering server process statistics. Includes a standalone Pure PHP webserver.

Currently the main function of the daemon is counting used 'jiffies' by processes. Jiffies are processor time units. In most cases one jiffy the 1/100th of a second (10 milliseconds) of the power of one CPU core.

The counters can be requested in JSON, Cacti or Nagios format.

For a quick start check [docs/install.md](https://github.com/jeroenvermeulen/clusterstat-daemon/blob/master/docs/install.md)

![ClusterStatD Main Screen](https://raw.githubusercontent.com/jeroenvermeulen/clusterstat-daemon/master/docs/clusterstatd_main_screen.png)
![ClusterStatD ProcStats Detail](https://raw.githubusercontent.com/jeroenvermeulen/clusterstat-daemon/master/docs/procstats_detail_example.png)
