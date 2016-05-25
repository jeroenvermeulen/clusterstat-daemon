CREATE TABLE "procstats" (
  "linuxuser"        VARCHAR ( 100 ) PRIMARY KEY  NOT NULL ,
  "process"          VARCHAR ( 100 )              NOT NULL ,
  "jiffies_last"     UNSIGNED BIG INT             NOT NULL  DEFAULT (0) ,
  "jiffies_counter"  UNSIGNED BIG INT             NOT NULL  DEFAULT (0) ,
  "ioread_last"      UNSIGNED BIG INT             NOT NULL  DEFAULT (0) ,
  "ioread_counter"   UNSIGNED BIG INT             NOT NULL  DEFAULT (0) ,
  "iowrite_last"     UNSIGNED BIG INT             NOT NULL  DEFAULT (0) ,
  "iowrite_counter"  UNSIGNED BIG INT             NOT NULL  DEFAULT (0)
)