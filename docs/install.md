Ubuntu
------

Install Required Packages
-------------------------

Ubuntu:

    apt-get -y install php5-cli php5-sqlite git-core

RedHat / CentOS:

    yum install php-cli php-pdo

Done.

Install from BitBucket on Ubuntu
--------------------------------

mkdir -p /usr/share
cd /usr/share
git clone git@bitbucket.org:jeroenvermeulen/clusterstat-daemon.git
ln -sfn /usr/share/clusterstat-daemon/clusterstatd /etc/init.d/clusterstatd
update-rc.d clusterstatd defaults
service clusterstatd start