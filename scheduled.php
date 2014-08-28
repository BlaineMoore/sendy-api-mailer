<?php
/*
 * File: scheduling/scheduled.php
 * Description: This file determines which is the best method for sending messages.
 * Version: 0.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 */
ini_set('display_errors', 1);

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}
$scheduling_start = microtime_float();
echo "<br />\nBegan at: $scheduling_start\n<br />\n";
include_once('includes/scheduling-config.php');
include_once('critsend.php'); // Includes support for Critsend
include_once('normal-sendy.php'); // Default: use the normal Sendy scheduled.php file...

//setup cron
$q = 'SELECT id, cron, send_rate, ses_endpoint FROM login LIMIT 1';
$r = mysqli_query($mysqli, $q);
if ($r)
{
    while($row = mysqli_fetch_array($r))
    {
        $cron = $row['cron'];
        $userid = $row['id'];
        $send_rate = $row['send_rate'];
        $ses_endpoint = $row['ses_endpoint'];

        if($cron==0)
        {
            $q2 = 'UPDATE login SET cron=1 WHERE id = '.$userid;
            $r2 = mysqli_query($mysqli, $q2);
            if ($r2) exit;
        }
    }
}

$the_offset = '';
$offset = isset($_GET['offset']) ? $_GET['offset'] : '';

//Check campaigns database
$q = 'SELECT timezone, sent, id, app, userID, to_send, to_send_lists, recipients, timeout_check, send_date, lists, from_name, from_email, reply_to, title, plain_text, html_text FROM campaigns WHERE (send_date !="" AND lists !="" AND timezone != "") OR (to_send > recipients) ORDER BY sent DESC';
$r = mysqli_query($mysqli, $q);
if ($r && mysqli_num_rows($r) > 0)
{
    while($row = mysqli_fetch_array($r))
    {
        //prepare variables
        $timezone = $row['timezone'];
        if($timezone!='0' && $timezone!='') date_default_timezone_set($timezone);
        $sent = $row['sent'];
        $campaign_id = $row['id'];
        $app = $row['app'];
        $userID = $row['userID'];
        $send_date = $row['send_date'];
        $email_list = $row['lists'];
        $time = time();
        $current_recipient_count = $row['recipients'];
        $timeout_check = $row['timeout_check'];
        $from_name = stripslashes($row['from_name']);
        $from_email = stripslashes($row['from_email']);
        $reply_to = stripslashes($row['reply_to']);
        $title = stripslashes($row['title']);
        $plain_text = stripslashes($row['plain_text']);
        $html = stripslashes($row['html_text']);
        $to_send_num = $row['to_send'];
        $to_send = $to_send_num;
        $to_send_lists = $row['to_send_lists'];

        //Set language
        $q_l = 'SELECT login.language FROM campaigns, login WHERE campaigns.id = '.$campaign_id.' AND login.app = campaigns.app';
        $r_l = mysqli_query($mysqli, $q_l);
        if ($r_l && mysqli_num_rows($r_l) > 0) while($row = mysqli_fetch_array($r_l)) $language = $row['language'];
        set_locale($language);

        //get user details
        $q2 = 'SELECT s3_key, s3_secret, name, username, timezone FROM login WHERE id = '.$userID;
        $r2 = mysqli_query($mysqli, $q2);
        if ($r2)
        {
            while($row = mysqli_fetch_array($r2))
            {
                $s3_key = $row['s3_key'];
                $s3_secret = $row['s3_secret'];
                $user_name = $row['name'];
                $user_email = $row['username'];
                $user_timezone = $row['timezone'];
            }
        }

        //get smtp settings
        $q3 = 'SELECT smtp_host, smtp_port, smtp_ssl, smtp_username, smtp_password FROM apps WHERE id = '.$app;
        $r3 = mysqli_query($mysqli, $q3);
        if ($r3 && mysqli_num_rows($r3) > 0)
        {
            while($row = mysqli_fetch_array($r3))
            {
                $smtp_host = $row['smtp_host'];
                $smtp_port = $row['smtp_port'];
                $smtp_ssl = $row['smtp_ssl'];
                $smtp_username = $row['smtp_username'];
                $smtp_password = $row['smtp_password'];
            }
        }

        //check if we should send email now
        if((($time>=$send_date && $time<$send_date+300) && $sent=='') || (($send_date<$time) && $sent=='') || ($send_date=='0' && $timezone=='0'))
        {
            switch($smtp_host) {
                case "smtp.critsend.com": scheduling_critsend($campaign_id); break;
                default: scheduling_normal($row); break;
            } // switch($smtp_host)

        }

        //check if sending timed out
        if($current_recipient_count > 0 && $current_recipient_count < $to_send_num && $offset == '')
        {
            //check time out
            $tc_array = explode(':', $timeout_check);
            $tc_prev = $tc_array[0];
            $tc_now = $current_recipient_count;
            $tc = $tc_now.':'.$tc_prev;

            //update status of timeout
            $q = 'UPDATE campaigns SET timeout_check = "'.$tc.'" WHERE id = '.$campaign_id;
            mysqli_query($mysqli, $q);

            //compare prev count with current recipients number count
            //if timed out
            if($tc_now == $tc_prev)
            {
                $q = 'UPDATE campaigns SET timeout_check = NULL, send_date = "0", timezone = "0" WHERE id = '.$campaign_id;
                mysqli_query($mysqli, $q);

                //continue sending
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_URL, APP_PATH.SCHEDULING_PATH.'/scheduled.php?offset='.$current_recipient_count);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                $data = curl_exec($ch);
            }
        }
        else if($current_recipient_count == $to_send_num)
        {
            $q = 'UPDATE campaigns SET timeout_check = NULL WHERE id = '.$campaign_id;
            mysqli_query($mysqli, $q);
        }
    }
}

$scheduling_end = microtime_float();
echo "<br />\nEnded at: $scheduling_end\n<br />\n\n<br />\n Total Run Time: " . ($scheduling_end-$scheduling_start) . " seconds\n";
?>