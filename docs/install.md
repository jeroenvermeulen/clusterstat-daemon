    ClusterStats  Copyright (C) 2013 Bas Peters & Jeroen Vermeulen
    This program comes with ABSOLUTELY NO WARRANTY;
    This is free software, and you are welcome to redistribute it under certain conditions;
    Licence: GNU General Public License v3.0. More info: http://www.gnu.org/licenses/

Install from GitHub on Ubuntu
--------------------------------

    apt-get install git php5-cli php5-sqlite git-core

    mkdir -p /usr/share
    cd /usr/share
    git clone https://github.com/jeroenvermeulen/clusterstat-daemon.git
    cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
    ln -sfn /usr/share/clusterstat-daemon/clusterstatd /etc/init.d/clusterstatd
    update-rc.d clusterstatd defaults
    service clusterstatd start


Install from GitHub on Redhat / CentOS
--------------------------------------

    yum install git php-cli php-pdo php-process

    mkdir -p /usr/share
    cd /usr/share
    git clone https://github.com/jeroenvermeulen/clusterstat-daemon.git
    cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
    ln -sfn /usr/share/clusterstat-daemon/clusterstatd /etc/init.d/clusterstatd
    chkconfig clusterstatd on
    service clusterstatd start
    
The default port is `8888`, default user is `stats`, default password `stats`.
