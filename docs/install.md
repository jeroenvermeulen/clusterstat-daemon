    ClusterStats  Copyright (C) 2013 Bas Peters & Jeroen Vermeulen
    This program comes with ABSOLUTELY NO WARRANTY;
    This is free software, and you are welcome to redistribute it under certain conditions;
    Licence: GNU General Public License v3.0. More info: http://www.gnu.org/licenses/

Ubuntu
------

Install Required Packages
-------------------------

Ubuntu:

    apt-get install php5-cli php5-sqlite git-core

RedHat / CentOS:

    yum install php-cli php-pdo

Done.

Install from BitBucket on Ubuntu
--------------------------------

apt-get install php5-cli php5-sqlite git-core

mkdir -p /usr/share
cd /usr/share
git clone git@bitbucket.org:jeroenvermeulen/clusterstat-daemon.git
cp /usr/share/clusterstat-daemon/includes/config.php.dist /usr/share/clusterstat-daemon/includes/config.php
ln -sfn /usr/share/clusterstat-daemon/clusterstatd /etc/init.d/clusterstatd
update-rc.d clusterstatd defaults
service clusterstatd start
