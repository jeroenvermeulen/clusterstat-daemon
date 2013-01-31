CREATE TABLE 'jiffy' (
    'linuxuser'  VARCHAR ( 100 ) NOT NULL
  , 'process'    VARCHAR ( 100 ) NOT NULL
  , 'lastvalue'  BIGINT
  , 'offset'     BIGINT
  , PRIMARY KEY (linuxuser, process) ) ;
