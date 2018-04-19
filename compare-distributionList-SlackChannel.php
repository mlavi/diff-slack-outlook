#!/usr/bin/php
<?php

# DEPENDENCIES:
# - php 5.5
# - diff (GNU diffutils) 2.8.1
# - ./slack_api_key.txt
#   https://api.slack.com/apps/A7T3D33RB/general
#   requires Slack Oauth access token with permission scopes of: (might get away with less)
#    groups:history, groups:read
#    team:read
#    users.profile:read
#    users:read, users:read.email
# - ./$alias . '.outlook.txt'
#    Outlook Distribution List, cut and paste from Mac client after expanding distribution list/group alias. 

# TODO: Use Outlook365 hosted email APIs for current distribution list seed,
#       in the meantime, cut and paste expanded list from Outlook client into text file.

#__ INIT ________
   $DEBUG = true;
   $DEBUG = false; #override
if ($argc > 1) {
   $alias = $argv[$argc-1]; #print_r($argv);
   print "Assuming last CLI argument is Slack channel name which matches the Outlook distribution list: $alias\n";
} else {
   $alias = 'sme-devops';
}

function outlook()
{ # Process Outlook distribution list into a normalized format

  global $alias, $DEBUG;

  ini_set('auto_detect_line_endings', TRUE);
     $group = array();
  $filename = $alias . '.outlook.txt';
    $handle = fopen($filename, 'rb') or exit("\nERROR: file not found $filename\n");
    $corpus = fread($handle, filesize($filename)); # echo $corpus;
    $people = explode(';', $corpus);
  fclose($handle);

  foreach($people as $person) {
       $start =   stripos ($person, '<') + 1;
      $length =   strripos($person, '@') - $start;
    $userName =     substr($person, $start, $length); # print strtolower($userName) . "\n";
     $group[] = strtolower($userName);
  }

  natsort($group); # print_r($group);

  $filename = $alias . '.outlook.processed.txt';
  $handle = fopen($filename, 'w') or exit("\nERROR: can not write $filename\n");
  foreach($group as $member) {
    fwrite($handle, "$member\n"); #print "$member\n";
  }
  fclose($handle);

  print "\n" . count($group) . " Outlook email addresses written to $filename";
}

function slackAPI($method, $arg, $slackAPIkey)
{
  global $DEBUG;

   $headers = array(
    'authorization: Bearer ' . $slackAPIkey,
    'cache-control: no-cache'
  );
  $httpVerb = 'GET';
       $url = 'https://api.slack.com/api/';

  if ($method == 'users.profile.get') {
    $headers[] = 'content-type: application/x-www-form-urlencoded';
     $httpVerb = 'POST';
       $method = "$method?user=$arg";
  } else {
    $headers[] = 'content-type: application/json';
  }
  #print_r($headers);

  print "\nCall slack:$method"; #. ", argument=|$arg|\n";
  $curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => $url . $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => $httpVerb,
    CURLOPT_HTTPHEADER => $headers,
  ));

  $response = curl_exec($curl);
  $err = curl_error($curl);
  curl_close($curl);

  if ($err) {
    echo "cURL Error #:" . $err;
  } else {
    #echo $response; var_dump(json_decode($response, true));
    #print "returing $response.";
    return json_decode($response, true);
  }
}

function slack()
{
  global $alias, $DEBUG;
            $i = 0;
         $step = 5;
     $filename = 'slack_api_key.txt';
       $handle = fopen($filename, 'rb') or exit("\nERROR: file not found $filename\n");
  $slackAPIkey = trim(fread($handle, filesize($filename)));
  fclose($handle);

  if ($DEBUG) { print "\nDEBUG: slackAPIkey=|$slackAPIkey|\n"; }

  # find the $alias group members
  $groups_list = slackAPI('groups.list', '', $slackAPIkey);

  foreach($groups_list['groups'] as $group) {
    #print_r($group);
    if ($group['name'] == $alias) {
      $members = $group['members'];
      #print_r($members);
      break;
    }
  }

  # lookup each group member's email
  $corpus = count($members);

  foreach($members as $member) {
     $profile = slackAPI('users.profile.get', $member, $slackAPIkey);
    $emails[] = substr($profile['profile']['email'],
      0,
      strlen($profile['profile']['email'])-12 #strlen('@nutanix.com') #optimization
    );
    #print_r($profile);
    #print $profile['profile']['email'];

    $i++;
    if ($i % $step == 0) {
      print "\n$i / $corpus...\n";
      if ($DEBUG) {
        print 'DEBUG: I proved my point: stopping now.';
        break;
      }
    }

  }

  natsort($emails); # print_r($emails);

  $filename = $alias . '.slack.processed.txt';
  $handle = fopen($filename, 'w') or exit("\nERROR: can not write $filename\n");
  foreach($emails as $email) {
    fwrite($handle, "$email\n"); #print "$email\n";
  }
  fclose($handle);

  print "\n" . count($emails) . ' Slack email addresses written to ' . $filename;
}

#___ MAIN _________

slack();
outlook();

print "\n" . 'diff --suppress-common-lines --side-by-side --ignore-blank-lines '
 . "$alias*processed.txt > $alias.diff.txt\n\n";
