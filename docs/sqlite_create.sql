CREATE TABLE "procstats" (
  "id"               ROWID,
  "linuxuser"        TEXT     NOT NULL ,
  "process"          TEXT     NOT NULL ,
  "jiffies_last"     INTEGER  NOT NULL  DEFAULT (0) ,
  "jiffies_counter"  INTEGER  NOT NULL  DEFAULT (0) ,
  "ioread_last"      INTEGER  NOT NULL  DEFAULT (0) ,
  "ioread_counter"   INTEGER  NOT NULL  DEFAULT (0) ,
  "iowrite_last"     INTEGER  NOT NULL  DEFAULT (0) ,
  "iowrite_counter"  INTEGER  NOT NULL  DEFAULT (0)
)
