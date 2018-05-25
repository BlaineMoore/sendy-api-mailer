<?php
/*
 * File: scheduling/scheduled.php
 * Description: This file determines which is the best method for sending messages. Compatible through v3.0.9.1
 * Version: 1.3.0.9.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 */
ini_set('display_errors', 0);

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
	$q = 'SELECT timezone, sent, id, app, userID, to_send, to_send_lists, recipients, timeout_check, send_date, lists, lists_excl, segs, segs_excl, from_name, from_email, reply_to, title, label, plain_text, html_text, query_string, opens_tracking, links_tracking FROM campaigns WHERE (send_date !="" AND lists !="" AND timezone != "") OR (to_send > recipients) ORDER BY sent DESC';
	$r = mysqli_query($mysqli, $q);
	if ($r && mysqli_num_rows($r) > 0)
	{
	    while($row = mysqli_fetch_array($r))
	    {
	    	//prepare variables
	    	$timezone = $row['timezone'];
			$sent = $row['sent'];
			$campaign_id = $row['id'];
			$app = $row['app'];
			$userID = $row['userID'];
			$send_date = $row['send_date'];
			$email_list = $row['lists'];
			$email_list_excl = $row['lists_excl'];
			$segs = $row['segs'];
			$segs_excl = $row['segs_excl'];
			$time = time();
			$current_recipient_count = $row['recipients'];
			$timeout_check = $row['timeout_check'];
			$from_name = stripslashes($row['from_name']);
	    	$from_email = stripslashes($row['from_email']);
	    	$reply_to = stripslashes($row['reply_to']);
			$title = stripslashes($row['title']);
			$campaign_title = $row['label']=='' ? $title : stripslashes(htmlentities($row['label'],ENT_QUOTES,"UTF-8"));
			$plain_text = stripslashes($row['plain_text']);
			$html = stripslashes($row['html_text']);
			$query_string = stripslashes($row['query_string']);
			$to_send_num = $row['to_send'];
			$to_send = $to_send_num;
			$to_send_lists = $row['to_send_lists'];
			$opens_tracking = $row['opens_tracking'];
			$links_tracking = $row['links_tracking'];
			
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
			
			//Set default timezone
			date_default_timezone_set($timezone!='0' && $timezone!='' ? $timezone : $user_timezone);
			
			//convert date tags
			$today = time();
			$day_word = array(_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday'));
			$month_word = array('', _('January'), _('February'), _('March'), _('April'), _('May'), _('June'), _('July'), _('August'), _('September'), _('October'), _('November'), _('December'));
			$currentdaynumber = strftime('%d', $today);
			$currentday = $day_word[strftime('%w', $today)];
			$currentmonthnumber = strftime('%m', $today);
			$currentmonth = $month_word[str_replace('0', '', strftime('%m', $today))];
			$currentyear = strftime('%Y', $today);
			$unconverted_date = array('[currentdaynumber]', '[currentday]', '[currentmonthnumber]', '[currentmonth]', '[currentyear]');
			$converted_date = array($currentdaynumber, $currentday, $currentmonthnumber, $currentmonth, $currentyear);
			
			//get smtp settings
			$q3 = 'SELECT smtp_host, smtp_port, smtp_ssl, smtp_username, smtp_password, notify_campaign_sent, gdpr_only FROM apps WHERE id = '.$app;
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
					$notify_campaign_sent = $row['notify_campaign_sent'];
					$gdpr_line = $row['gdpr_only'] ? 'AND gdpr = 1 ' : '';
			    }  
			}
			
			if($offset=='') //If sending for the first time
				$main_query = $email_list == 0 ? '' : 'subscribers.list in ('.$email_list.') '; //Include main list query
			else //If resuming
				$main_query = $to_send_lists == '' ? '' : 'subscribers.list in ('.$to_send_lists.') '; //Include main list query
			
			//Include segmentation query
			$seg_query = $main_query != '' && $segs != 0 ? 'OR ' : '';
			$seg_query .= $segs == 0 ? '' : '(subscribers_seg.seg_id IN ('.$segs.')) ';
			
			//Exclude list query
			$exclude_query = $email_list_excl == 0 ? '' : 'subscribers.email NOT IN (SELECT email FROM subscribers WHERE list IN ('.$email_list_excl.')) ';
			
			//Exclude segmentation query
			$exclude_seg_query = $exclude_query != '' && $segs_excl != 0 ? 'AND ' : ''; 
			$exclude_seg_query .= $segs_excl == 0 ? '' : 'subscribers.email NOT IN (SELECT subscribers.email FROM subscribers LEFT JOIN subscribers_seg ON (subscribers.id = subscribers_seg.subscriber_id) WHERE subscribers_seg.seg_id IN ('.$segs_excl.'))';
			
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
$totalTime = $scheduling_end-$scheduling_start;
echo "<br />\nEnded at: $scheduling_end\n<br />\n\n<br />\n Total Run Time: " . $totalTime . " seconds\n";
?>
