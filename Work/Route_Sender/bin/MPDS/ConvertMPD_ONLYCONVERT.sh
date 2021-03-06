#!/bin/bash

#This script is intended to transform static MPD generated by MP4BOX to dynamic. This is done by:
#1- Change type="static" to type="dynamic"
#2- Add availabilityStartTime at MPD level
#3- Set Period ids incrementally in case they are empty

if [ $# -ne 3 ]
then
	echo "Usage: ./ConvertMPD.sh MPDName VideoRepresentationID AudioRepresentationID"
	exit
fi 

period=1;	#This is used to incrementally set periods in MPD
toPrint=1;	#This is used to generate new MPD with only 1 video and 1 audio representation.
		#It assumes that audio and video are in seperate adaptation sets

a=$(awk -v startTime="$(date +"%Y-%m-%dT%T" --date='7 seconds')" -v period=$period -v toPrint=$toPrint -v MPDName=$1 -v vidRepID=$2 -v audRepID=$3 'BEGIN {value="";gsub(/\./,"_Dynamic.",MPDName)} {sub(/type="static"/,"type=\"dynamic\" availabilityStartTime=\""startTime"+01:00\" timeShiftBufferDepth=\"PT10S\"");if ($1=="<Period") {sub(/id=""/,"start=\"PT0S\" id=\""period"\""); period++}; if (($1=="<Representation" && index($0,"id=\""vidRepID"\"") == 0 && index($0,"mimeType=\"video") > 0) ||($1=="<Representation" && index($0,"id=\""audRepID"\"") == 0 && index($0,"mimeType=\"audio") > 0)) toPrint=0;if (toPrint==1) {for (i=NF-1;i>1;i--) if (index($i,"timescale=") > 0 || index($i,"duration=") > 0) {value=$i;gsub(/[a-z,A-Z,=,"]/,"",value); print value} else if (index($i,"media=") > 0) {value=$i;gsub(/\$Number\$\.mp4/,"",value); print value};print $0 > MPDName}; if (index($0,"</Representation") > 0) toPrint=1}' $1)



