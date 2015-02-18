Install Upstart script on Debian and Ubuntu:

  sudo cp /usr/share/clusterstat-daemon/docs/upstart/clusterstatd.conf /etc/init/clusterstatd.conf
  sudo initctl reload-configuration
  sudo initctl start clusterstatd