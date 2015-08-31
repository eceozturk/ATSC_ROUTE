<?php

echo $argv[0]. PHP_EOL;
if (isset($argv[0])) {
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}
$adInsertion = TRUE;
$adInsertionTime = 60;  //In seconds from start
$OriginalMPD=$_GET['MPD'];
$PatchedMPD=$_GET['uMPD'];
$AST_W3C=$_GET['AST'];

$AST_SEC = new DateTime( 'now',  new DateTimeZone( 'UTC' ) );	/* initializer for availability start time */
$AST_SEC->add(new DateInterval('PT1S'));   //????????????????? some rounding issue??
$AST_SEC_W3C = $AST_SEC->format(DATE_W3C);

$MPD = simplexml_load_file($OriginalMPD);
if (!$MPD)
        die("Failed loading XML file");

$dom_sxe = dom_import_simplexml($MPD);
if (!$dom_sxe) 
{
    echo 'Error while converting XML';
    exit;
}

$dom = new DOMDocument('1.0');
$dom_sxe = $dom->importNode($dom_sxe, true);
$dom_sxe = $dom->appendChild($dom_sxe);

$periods = parseMPD($dom->documentElement);

$cumulativeOriginalDuration = 0;    //Cumulation of period duration on source MPD
$cumulativeUpdatedDuration = 0;    //Cumulation of period duration on updated MPD

$originalAST = new DateTime($periods[0]['node']->parentNode->getAttribute("availabilityStartTime"));   

$periods[0]['node']->parentNode->setAttribute("type","dynamic");

$mediaPresentationDuration=$periods[0]['node']->parentNode->getAttribute("mediaPresentationDuration");
$periods[0]['node']->parentNode->removeAttribute("mediaPresentationDuration");

$profiles=$periods[0]['node']->parentNode->getAttribute("profiles");
$periods[0]['node']->parentNode->removeAttribute("profiles");

$periods[0]['node']->parentNode->setAttribute("availabilityStartTime",$AST_W3C);    //Set AST to tune-in time
$periods[0]['node']->parentNode->setAttribute("timeShiftBufferDepth","PT5S");
$periods[0]['node']->parentNode->setAttribute("mediaPresentationDuration",$mediaPresentationDuration);
$periods[0]['node']->parentNode->setAttribute("profiles",$profiles);

$savedTotalDuration = 0;
$restPeriodDuration = 0;
$numVideoSegments = 0;
$numAudioSegments = 0;

for ($periodIndex = 0; $periodIndex < count($periods); $periodIndex++)  //Loop on all periods in orginal MPD
{
    $durationInMPD =$periods[$periodIndex]['node']->getAttribute("duration");
    $duration = somehowPleaseGetDurationInFractionalSecondsBecuasePHPHasABug($durationInMPD);
    
    if($adInsertion)
    {
        $videoSegmentTemplate = &$periods[$periodIndex]['adaptationSet'][0]['representation'][0]['segmentTemplate']['node'];
        $audioSegmentTemplate = &$periods[$periodIndex]['adaptationSet'][1]['representation'][0]['segmentTemplate']['node'];
        
        $videoTimescale = $videoSegmentTemplate->getAttribute("timescale");
        $videoSegmentDuration = $videoSegmentTemplate->getAttribute("duration");
        $videoStartNum = $videoSegmentTemplate->getAttribute("startNumber");
        $videoPTO = $videoSegmentTemplate->getAttribute("presentationTimeOffset");
        
        $audioTimescale = $audioSegmentTemplate->getAttribute("timescale");
        $audioSegmentDuration = $audioSegmentTemplate->getAttribute("duration");
        $audioStartNum = $audioSegmentTemplate->getAttribute("startNumber");
        $audioPTO = $audioSegmentTemplate->getAttribute("presentationTimeOffset");
        
        if($periodIndex == 0)
        {
            $numVideoSegments = ceil($adInsertionTime*$videoTimescale/$videoSegmentDuration);
            $numAudioSegments = ceil($adInsertionTime*$audioTimescale/$audioSegmentDuration);
            
            $savedTotalDuration = $duration;            
            
            if($numVideoSegments*$videoSegmentDuration/$videoTimescale > $numAudioSegments*$audioSegmentDuration/$audioTimescale)
            {
                $duration = $numVideoSegments*$videoSegmentDuration/$videoTimescale;
                $numAudioSegments = ceil($duration*$audioTimescale/$audioSegmentDuration);
            }
            else
            {
                $duration = $numAudioSegments*$audioSegmentDuration/$audioTimescale;
                $numVideoSegments = ceil($duration*$videoTimescale/$videoSegmentDuration);                
            }
            
            $restPeriodDuration = $savedTotalDuration - $duration;
        }
        else if($periodIndex == 1)      //!!!!!!!!!!!!!!!!!!!!!!!!!
        {
            $duration = $restPeriodDuration;
            
            $newVideoStartNumber = $numVideoSegments + $videoStartNum;
            $newVideoPTO = ($newVideoStartNumber - $videoStartNum) * $videoSegmentDuration + $videoPTO;

            $newAudioStartNumber = $numAudioSegments + $audioStartNum;
            $newAudioPTO = ($newAudioStartNumber - $audioStartNum) * $audioSegmentDuration + $audioPTO; 

            // Find the smaller PTO of audio and video, set the other to the smaller
            if($newVideoPTO/$videoTimescale < $newAudioPTO/$audioTimescale)
            {
              $newAudioPTO = round($newVideoPTO/$videoTimescale*$audioTimescale);
            }
            else
            {
              $newVideoPTO = round($newAudioPTO/$audioTimescale*$videoTimescale);
            }        

            $videoSegmentTemplate->setAttribute("presentationTimeOffset",$newVideoPTO);
            $videoSegmentTemplate->setAttribute("startNumber",$newVideoStartNumber);

            $audioSegmentTemplate->setAttribute("presentationTimeOffset",$newAudioPTO);
            $audioSegmentTemplate->setAttribute("startNumber",$newAudioStartNumber);
        }
    }

    $cumulativeDurationPreceedingPeriods = $cumulativeOriginalDuration; //Save it for later use
    $cumulativeOriginalDuration += $duration; 

    $periods[$periodIndex]['node']->removeAttribute("id");
    $periods[$periodIndex]['node']->removeAttribute("duration");
    
    $periods[$periodIndex]['node']->setAttribute("start","PT".round($cumulativeDurationPreceedingPeriods,2) ."S");
    $periods[$periodIndex]['node']->setAttribute("id",$periodIndex+1);
    $periods[$periodIndex]['node']->setAttribute("duration", "PT". round($duration , 2) . "S");
}

$dom->save($PatchedMPD);
    
function &parseMPD($docElement)
{
    global $adInsertion;
    $finalPeriodInserted = FALSE;
    
    foreach ($docElement->childNodes as $node)
    {
        //echo $node->nodeName; // body
        if($node->nodeName === 'Location')
            $locationNode = $node;
        if($node->nodeName === 'BaseURL')
            $baseURLNode = $node;    
        if($node->nodeName === 'Period')
        {
            if($adInsertion && !$finalPeriodInserted)
            {
                $finalPeriod = $node->cloneNode (true);
                $node->parentNode->appendChild($finalPeriod);
                $finalPeriodInserted = TRUE;
            }
            
            $periods[]['node'] = $node;

            $currentPeriod = &$periods[count($periods) - 1];
            foreach ($currentPeriod['node']->childNodes as $node)
            {
                if($node->nodeName === 'AdaptationSet')
                {
                    $currentPeriod['adaptationSet'][]['node'] = $node;
                    
                    $currentAdaptationSet = &$currentPeriod['adaptationSet'][count($currentPeriod['adaptationSet']) - 1];                    
                    foreach ($currentAdaptationSet['node']->childNodes as $node)
                    {
                        if($node->nodeName === 'Representation')
                        {
                            $currentAdaptationSet['representation'][]['node'] = $node;
                            
                            $currentRepresentation = &$currentAdaptationSet['representation'][count($currentAdaptationSet['representation']) - 1];

                            foreach ($currentRepresentation['node']->childNodes as $node)
                            {
                                if($node->nodeName === 'SegmentTemplate')
                                    $currentRepresentation['segmentTemplate']['node'] = $node;
                            }
                        }
                    }
                }
            }            
        }
    }
    
    return $periods;
}

function somehowPleaseGetDurationInFractionalSecondsBecuasePHPHasABug($durstr)
{
    
        $durstrint = explode('.', $durstr)[0] . 'S';
        $fracSec = '0.' . explode('S',explode('.', $durstr)[1])[0];
        $di = new DateInterval($durstrint);

        $durationDT = new DateTime();
        $reft = new DateTime();
        $durationDT->add($di);
        $duration = $durationDT->getTimestamp() - $reft->getTimestamp() + $fracSec;
        
        return $duration;
}
?>