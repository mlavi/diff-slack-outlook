#!/usr/bin/php
<?php

# DEPENDENCIES:
# - php 5.5
# - diff (GNU diffutils) 2.8.1
# - Static configuration files, defined in variables, below:
#   - ./$ACCOUNT_SUPPRESSION (file defined below)
#   - ./$SLACK_TO_PRIMARY_EMAIL (file defined below)
#   - ./$SLACK_API_KEY (file defined below)
#   https://api.slack.com/apps/A7T3D33RB/general
#   requires Slack Oauth access token with permission scopes of: (might get away with less)
#    groups:history, groups:read
#    team:read
#    users.profile:read
#    users:read, users:read.email
#    - ./$ALIAS . '.outlook.txt'
#    Outlook Distribution List, cut and paste from Mac client after expanding distribution list/group alias.

# TODO: Use Outlook365 hosted email APIs for current distribution list seed,
#       in the meantime, cut and paste expanded list from Outlook client into text file.

#__ INIT ________
$DEBUG = true;
$DEBUG = false; #override

       $CACHE_DIRECTORY = 'cache';
   $ACCOUNT_SUPPRESSION = 'account_suppression.txt';
                   $MAP = array();
                $MAPPED = 0;
         $SLACK_API_KEY = 'slack_api_key.txt';
$SLACK_TO_PRIMARY_EMAIL = 'slack2email.txt';
              $SURPRESS = array();
            $SURPRESSED = 0;

$ALIAS = 'sme-devops'; #default when no CLI argument offered
if ($argc > 1) {
   $ALIAS = $argv[$argc-1]; #print_r($argv);
   print "Assuming last CLI argument is Slack channel name which matches the Outlook distribution list: $ALIAS\n";
}

function domain_strip($email)
{
  $position = strlen($email) - strrpos($email, '@', -4); # optimization: @x.xx would be the minimum
  if ($position <> 12) { # optimization for strlen('@nutanix.com')
    echo "Warning: non NTNX domain = $email: $position\n";
  }
  return substr($email, 0, $position * -1);
}

function init()
{
  # To improve matching, load these lists:
  # - Inconsistency between Slack email and primary Outlook email: map overide list
  # - Supression list for email suspended accounts, etc. that are still listed without status
  global $DEBUG, $ACCOUNT_SUPPRESSION, $MAP, $SLACK_TO_PRIMARY_EMAIL, $SURPRESS;

  $handle = fopen($SLACK_TO_PRIMARY_EMAIL, 'rb') or exit("\nError: file not found $SLACK_TO_PRIMARY_EMAIL\n");
  $corpus = fread($handle, filesize($SLACK_TO_PRIMARY_EMAIL));
  fclose($handle);

  $mapping = explode("\n", $corpus);

  foreach($mapping as $line) {
    if ( (strlen(trim($line)) == 0) || (substr($line, 0, 1) == '#') ) {
      message('map_init|skipping empty line or comment.');
    } else {
      list($slack, $email) = explode('|', $line);
      message("map_init|$slack|$email|");
      $MAP[] = array('slack' => $slack, 'email' => $email,);
    }
  }
  #var_dump($MAP);

  $handle = fopen($ACCOUNT_SUPPRESSION, 'rb') or exit("\nError: file not found $ACCOUNT_SUPPRESSION\n");
  $corpus = fread($handle, filesize($ACCOUNT_SUPPRESSION));
  fclose($handle);

  $mapping = explode("\n", $corpus);
  foreach($mapping as $line) {
    if ( (strlen(trim($line)) == 0) || (substr($line, 0, 1) == '#') ) {
      message('map_init|skipping empty line or comment.');
    } else {
      $SURPRESS[] = $line;
    }
  }
  #var_dump($SURPRESS);

}

function map($name)
{
  global $MAP, $MAPPED; # var_dump($MAP);

  foreach($MAP as $map) {
    message("map|" . $map['slack'] . '|' . $map['email']);

    if ($name == $map['email']) {
      $name = $map['slack'];
      print("map|updated $name\n");
      $MAPPED++;
      break;
    }
  }

  return $name;
}

function message($line)
{
  global $DEBUG;

  if ($DEBUG) {
    echo "DEBUG|$line\n";
  // } else {
  //   echo "$line\n";
  }
}

function outlook_process()
{ # Process Outlook distribution list into a normalized format

  global $ALIAS, $DEBUG, $SURPRESS;

  ini_set('auto_detect_line_endings', TRUE);
     $group = array();
  $filename = $ALIAS . '.outlook.txt';
  print "\noutlook_process|Input: $filename\n";

  $handle = fopen($filename, 'rb') or exit("\nError: file not found $filename\n");
  $corpus = fread($handle, filesize($filename));
  $people = explode(';', $corpus);
  fclose($handle);

  foreach($people as $person) {
      $start =           stripos($person, '<') + 1;
     $length =          strripos($person, '@') - $start;
    $account = strtolower(substr($person, $start, $length)); # print($account) . "\n";

    if (surpress($account) != '') {
      $group[] = $account;
    }
    /* else list generator:
      $group[] = $full_name . ',' . $account . '@nutanix.com';
    */
  }

  natsort($group); # print_r($group);

  $filename = $ALIAS . '.outlook.processed.txt';
    $handle = fopen($filename, 'w') or exit("\nError: can not write $filename\n");
  foreach($group as $member) {
    fwrite($handle, "$member\n"); #print "$member\n";
  }
  fclose($handle);

  print 'outlook_process| ' . count($group)
    . " Outlook email addresses written to $filename\n";
}

function slack_api($method, $arg, $slack_api_key)
{
   $headers = array(
    'authorization: Bearer ' . $slack_api_key,
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

  print "slack_api|:$method, argument=|$arg|\n";
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

function slack_process()
{
  global $ALIAS, $CACHE_DIRECTORY, $DEBUG, $SLACK_API_KEY, $SURPRESS;
              $i = 0;
           $step = 10;
         $handle = fopen($SLACK_API_KEY, 'rb') or exit("\nError: file not found $SLACK_API_KEY\n");
  $slack_api_key = trim(fread($handle, filesize($SLACK_API_KEY)));
  fclose($handle);

  message("slack_process|slack_apikey=|$slack_api_key|");

  # find all groups/channels
  $groups_list = slack_api('groups.list', '', $slack_api_key);

  # find the $ALIAS group members
  foreach($groups_list['groups'] as $group) {
    #print_r($group);
    if ($group['name'] == $ALIAS) {
      $members = $group['members'];
      #print_r($members);
      break;
    }
  }

  # lookup each group member's email
  $corpus = count($members);

  foreach($members as $member) {

    $filename="$CACHE_DIRECTORY/$member.txt";
    if ( file_exists($filename) ) {

      $candidate = map(domain_strip(trim(file_get_contents($filename))));

      if (strlen($candidate) == 0) {
        echo "Skip: $member = empty $candidate\n";
      } elseif (surpress($candidate) != '') {
        $emails[] = $candidate;
      }

      message("Found $filename; candidate=$candidate");

    } else {

       $profile = slack_api('users.profile.get', $member, $slack_api_key);
         $email = $profile['profile']['email'];
      $emails[] = domain_strip($email);
      #print_r($profile);
      #print $profile['profile']['email'];
      $handle = fopen($filename, 'w') or exit("\nError: can not write $filename\n");
      fwrite($handle, "$email\n"); #print "$email\n";
      fclose($handle);
    }

    $i++;
    if ($i % $step == 0) {
      print "$i / $corpus...\n";
      if ($DEBUG) {
        message('I proved my point: stopping now.');
        break;
      }
    }

  }

  natsort($emails); # print_r($emails);

  $filename = $ALIAS . '.slack.processed.txt';
    $handle = fopen($filename, 'w') or exit("\nError: can not write $filename\n");
  foreach($emails as $email) {
    fwrite($handle, "$email\n"); #print "$email\n";
  }
  fclose($handle);

  print "slack_process| " . count($emails)
    . ' Slack email addresses written to ' . $filename . "\n";
}

function surpress($candidate)
{
  global $DEBUG, $SURPRESS, $SURPRESSED;

  $surpression_flag = FALSE;

  foreach ($SURPRESS as $account) {
    if ($candidate == $account) {
      print "surpress|Omitting email account: $account == $candidate.\n";
      $surpression_flag = TRUE;
      $SURPRESSED++;
      break;
    // } else {
    //   print "surpress|Keeping email account: $account != $candidate.\n";
    }
  }

  if ($surpression_flag === FALSE) {
    return($candidate);
  }
}
#___ MAIN ________

init();
slack_process();
outlook_process();

     $population_map = count($MAP);
$population_surpress = count($SURPRESS);

echo <<<EoM

Summary:
- Mapped list count: $MAPPED / $population_map
-  Supression count: $SURPRESSED / $population_surpress

diff --suppress-common-lines --side-by-side --ignore-blank-lines \
  $ALIAS*processed.txt > $ALIAS.diff.txt ; echo; ls -l $ALIAS.outlook.txt
EoM;
