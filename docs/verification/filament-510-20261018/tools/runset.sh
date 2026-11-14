#!/bin/bash
# usage: runset.sh <outdir> <filelist>  ; runs each test file in its own process, 3 at a time, with JUnit
S={scratch}
O=$1; L=$2; mkdir -p $O
export APP_KEY=base64:$(head -c32 /dev/urandom | base64)
cd /home/user/wt/m3
cat $L | xargs -P 3 -I{} bash -c 'b=$(basename {} .php); '$S'/pt.sh {} --log-junit '$O'/$b.xml > '$O'/$b.txt 2>&1; echo "$b done" >> '$O'/_progress.txt'
echo ALLDONE >> $O/_progress.txt
