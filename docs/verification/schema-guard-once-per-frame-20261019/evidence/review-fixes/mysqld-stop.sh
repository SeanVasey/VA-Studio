#!/bin/bash
B=/opt/mysql-8.4.11/bin
$B/mysql -uroot -h127.0.0.1 -P3417 -N -e "select @@datadir" 2>/dev/null | grep -q "g1-mysql/data" || { echo "not my server on 3417"; exit 0; }
$B/mysqladmin -uroot -h127.0.0.1 -P3417 shutdown
