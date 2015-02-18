Install SysVinit script on Debian and Ubuntu:

  sudo cp /usr/share/clusterstat-daemon/docs/sysvinit/clusterstatd /etc/init.d/clusterstatd
  sudo update-rc.d clusterstatd defaults
  sudo service clusterstatd start

Install SysVinit script on Red Hat and CentOS:

  sudo cp /usr/share/clusterstat-daemon/docs/sysvinit/clusterstatd /etc/init.d/clusterstatd
  sudo chkconfig clusterstatd on
  sudo service clusterstatd start

