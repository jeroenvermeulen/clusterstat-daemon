    ClusterStats  Copyright (C) 2013 Bas Peters & Jeroen Vermeulen
    This program comes with ABSOLUTELY NO WARRANTY;
    This is free software, and you are welcome to redistribute it under certain conditions;
    Licence: GNU General Public License v3.0. More info: http://www.gnu.org/licenses/


The default port is `8888`, default user is `stats`, default password `letmein`.

All install instructions below need to be executed as user *root*.

Install from GitHub on Ubuntu (Upstart)
---------------------------------------

    apt-get install git-core php5-cli php5-sqlite curl debian-helper-scripts

    mkdir -p /usr/share
    cd /usr/share
    git clone https://github.com/jeroenvermeulen/clusterstat-daemon.git
    cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
    cp /usr/share/clusterstat-daemon/docs/upstart/clusterstatd.conf /etc/init/clusterstatd.conf
    initctl reload-configuration
    initctl start clusterstatd


Install via ZIP on (older) Ubuntu (SysVinit)
--------------------------------------------

    apt-get install unzip wget php5-cli php5-sqlite debian-helper-scripts

    mkdir -p /usr/share
    cd /usr/share
    wget --no-check-certificate https://github.com/jeroenvermeulen/clusterstat-daemon/archive/master.zip -O clusterstat-daemon.zip
    unzip clusterstat-daemon.zip
    mv clusterstat-daemon-master clusterstat-daemon
    cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
    cp /usr/share/clusterstat-daemon/docs/sysvinit/clusterstatd /etc/init.d/clusterstatd
    update-rc.d clusterstatd defaults
    service clusterstatd start

Install from GitHub on Redhat / CentOS
--------------------------------------

    yum install git php-cli php-pdo php-process curl

    mkdir -p /usr/share
    cd /usr/share
    git clone https://github.com/jeroenvermeulen/clusterstat-daemon.git
    cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
    cp /usr/share/clusterstat-daemon/docs/sysvinit/clusterstatd /etc/init.d/clusterstatd
    chkconfig clusterstatd on
    service clusterstatd start
