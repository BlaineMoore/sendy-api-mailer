<?php
/*
 * File: scheduling/critsend.php
 * Description: This file provides a method for sending using the Critsend API.
 * Version: 0.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 */
include_once("critsend-connector.php");
$mxm = new MxmConnect();

function scheduling_critsend($campaign_id) {
    global $mysqli;
    global $offset;
    global $the_offset;
    global $scheduling_start;

    //Check campaigns database
    $q = 'SELECT timezone, sent, id, app, userID, to_send, to_send_lists, recipients, timeout_check, send_date, lists, from_name, from_email, reply_to, title, plain_text, html_text FROM campaigns WHERE id = '.$campaign_id.' AND ((send_date !="" AND lists !="" AND timezone != "") OR (to_send > recipients)) ORDER BY sent DESC';
    $r = mysqli_query($mysqli, $q);
    if ($r && mysqli_num_rows($r) > 0)
    {
        while($row = mysqli_fetch_array($r))
        {

            //re-prepare variables
            $timezone = $row['timezone'];
            if($timezone!='0' && $timezone!='') date_default_timezone_set($timezone);
            $sent = $row['sent'];
            //$campaign_id = $row['id'];
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
            $scheduling_critsend_max_emails_per_transmission = 495;

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
                //if resuming
                if($offset!='')
                    $q = 'UPDATE campaigns SET send_date=NULL, lists=NULL, timezone=NULL WHERE id = '.$campaign_id;
                else
                    $q = 'UPDATE campaigns SET sent = "'.$time.'", send_date=NULL, lists=NULL, timezone=NULL WHERE id = '.$campaign_id;
                $r = mysqli_query($mysqli, $q);
                if ($r){}

                //if sending for the first time
                if($offset=='')
                {
                    //Insert web version link
                    if(strpos($html, '</webversion>')==true)
                    {
                        mysqli_query($mysqli, 'INSERT INTO links (campaign_id, link) VALUES ('.$campaign_id.', "'.APP_PATH.'/w/'.short($campaign_id).'")');
                    }

                    //Insert into links
                    $links = array();
                    //extract all links from HTML
                    preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches, PREG_PATTERN_ORDER);
                    $matches = array_unique($matches[1]);
                    foreach($matches as $var)
                    {
                        if(substr($var, 0, 1)!="#" && substr($var, 0, 6)!="mailto" && substr($var, 0, 13)!="[unsubscribe]" && substr($var, 0, 12)!="[webversion]")
                        {
                            array_push($links, $var);
                        }
                    }
                    //extract unique links
                    for($i=0;$i<count($links);$i++)
                    {
                        $q = 'INSERT INTO links (campaign_id, link) VALUES ('.$campaign_id.', "'.$links[$i].'")';
                        $r = mysqli_query($mysqli, $q);
                        if ($r){}
                    }

                    //Get and update number of recipients to send to
                    $q = 'SELECT id FROM subscribers WHERE list in ('.$email_list.') AND unsubscribed = 0 AND bounced = 0 AND complaint = 0 AND confirmed = 1 GROUP BY email';
                    $r = mysqli_query($mysqli, $q);
                    if ($r)
                    {
                        $to_send = mysqli_num_rows($r);
                        $to_send_num = 	$to_send;
                        $q2 = 'UPDATE campaigns SET to_send = '.$to_send.', to_send_lists = "'.$email_list.'" WHERE id = '.$campaign_id;
                        $r2 = mysqli_query($mysqli, $q2);
                        if ($r2){}
                    }
                }
                else
                {
                    //if resuming
                    $email_list = $to_send_lists;
                    //get currently unsubscribed
                    $uc = 'SELECT id FROM subscribers WHERE unsubscribed = 1 AND last_campaign = '.$campaign_id;
                    $currently_unsubscribed = mysqli_num_rows(mysqli_query($mysqli, $uc));
                    //get currently bounced
                    $bc = 'SELECT id FROM subscribers WHERE bounced = 1 AND last_campaign = '.$campaign_id;
                    $currently_bounced = mysqli_num_rows(mysqli_query($mysqli, $bc));
                    //get currently complaint
                    $cc = 'SELECT id FROM subscribers WHERE complaint = 1 AND last_campaign = '.$campaign_id;
                    $currently_complaint = mysqli_num_rows(mysqli_query($mysqli, $cc));
                    //calculate offset (offset should exclude currently unsubscribed, bounced or complaint)
                    $the_offset = ' OFFSET '.($offset-($currently_unsubscribed+$currently_bounced+$currently_complaint));
                }

                //Replace links in newsletter and put tracking image
                $q = 'SELECT id, name, email, list, custom_fields FROM subscribers WHERE list in ('.$email_list.') AND unsubscribed = 0 AND bounced = 0 AND complaint = 0 AND confirmed = 1 GROUP BY email  ORDER BY id ASC LIMIT 18446744073709551615'.$the_offset;
                $r = mysqli_query($mysqli, $q);
                if ($r && mysqli_num_rows($r) > 0)
                {
                    $html_treated = $html;
                    $plain_treated = $plain_text;
                    $title_treated = $title;

                    $scheduling_short_email = '<mxm var="field1" />'; // short($email)
                    $scheduling_short_subscriber_list = '<mxm var="field2" />'; // short($subscriber_list)
                    $scheduling_short_subscriber_id = '<mxm var="field3" />'; // short($subscriber_id)
                    $scheduling_email = '<mxm var="email" />'; // $email
                    $scheduling_tags = array(); // custom fields
                    $scheduling_tag_counter = 4; // numerical assignment for each custom field
                    $scheduling_tag_fallbacks = array(); // fallbacks for each custom field (limitation: 1 fallback per custom field)
                    $scheduling_email_counter = 0; // number of emails collected so far
                    $scheduling_emails = array(); // data for each email collected so far

                    // replace new links on HTML code
                    $q2 = 'SELECT id, link FROM links WHERE campaign_id = '.$campaign_id;
                    $r2 = mysqli_query($mysqli, $q2);
                    if ($r2 && mysqli_num_rows($r2) > 0)
                    {
                        while($row2 = mysqli_fetch_array($r2))
                        {
                            $linkID = $row2['id'];
                            $link = $row2['link'];

                            //replace new links on HTML code
                            $html_treated = str_replace('href="'.$link.'"', 'href="'.APP_PATH.'/l/'.$scheduling_short_subscriber_id.'/'.short($linkID).'/'.short($campaign_id).'"', $html_treated);
                            $html_treated = str_replace('href=\''.$link.'\'', 'href="'.APP_PATH.'/l/'.$scheduling_short_subscriber_id.'/'.short($linkID).'/'.short($campaign_id).'"', $html_treated);

                            //replace new links on Plain Text code
                            $plain_treated = str_replace($link, APP_PATH.'/l/'.$scheduling_short_subscriber_id.'/'.short($linkID).'/'.short($campaign_id), $plain_treated);
                        }
                    }  // replace new links on HTML code

                    //set web version links
                    $html_treated = str_replace('<webversion', '<a href="'.APP_PATH.'/w/'.$scheduling_short_subscriber_id.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id).'" ', $html_treated);
                    $html_treated = str_replace('</webversion>', '</a>', $html_treated);
                    $html_treated = str_replace('[webversion]', APP_PATH.'/w/'.$scheduling_short_subscriber_id.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id), $html_treated);
                    $plain_treated = str_replace('[webversion]', APP_PATH.'/w/'.$scheduling_short_subscriber_id.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id), $plain_treated);

                    //set unsubscribe links
                    $html_treated = str_replace('<unsubscribe', '<a href="'.APP_PATH.'/unsubscribe/'.$scheduling_short_email.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id).'" ', $html_treated);
                    $html_treated = str_replace('</unsubscribe>', '</a>', $html_treated);
                    $html_treated = str_replace('[unsubscribe]', APP_PATH.'/unsubscribe/'.$scheduling_short_email.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id), $html_treated);
                    $plain_treated = str_replace('[unsubscribe]', APP_PATH.'/unsubscribe/'.$scheduling_short_email.'/'.$scheduling_short_subscriber_list.'/'.short($campaign_id), $plain_treated);

                    //Email tag
                    $html_treated = str_replace('[Email]', $scheduling_email, $html_treated);
                    $plain_treated = str_replace('[Email]', $scheduling_email, $plain_treated);
                    $title_treated = str_replace('[Email]', $scheduling_email, $title_treated);

                    //add tracking 1 by 1px image
                    $html_treated .= '<img src="'.APP_PATH.'/t/'.short($campaign_id).'/'.$scheduling_short_subscriber_id.'" alt=""/>';

                    //tags for subject
                    preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $title_treated, $matches_var, PREG_PATTERN_ORDER);
                    preg_match_all('/,\s*fallback=([a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*)\]/i', $title_treated, $matches_val, PREG_PATTERN_ORDER);
                    preg_match_all('/(\[[a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+,\s*fallback=[a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*\])/i', $title_treated, $matches_all, PREG_PATTERN_ORDER);
                    $matches_var = $matches_var[1];
                    $matches_val = $matches_val[1];
                    $matches_all = $matches_all[1];
                    for($i=0;$i<count($matches_var);$i++)
                    {
                        $field = $matches_var[$i];
                        $fallback = $matches_val[$i];
                        $tag = $matches_all[$i];

                        if(!isset($scheduling_tags[$field])) { $scheduling_tags[$field] = $scheduling_tag_counter++; }
                        $scheduling_tag_fallbacks[$field] = $fallback;

                        $title_treated = str_replace($tag, '<mxm var="field'.$scheduling_tags[$field].'" />', $title_treated);
                    } //  for($i=0;$i<count($matches_var);$i++) // subject tags

                    preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $html_treated, $matches_var, PREG_PATTERN_ORDER);
                    preg_match_all('/,\s*fallback=([a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*)\]/i', $html_treated, $matches_val, PREG_PATTERN_ORDER);
                    preg_match_all('/(\[[a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+,\s*fallback=[a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*\])/i', $html_treated, $matches_all, PREG_PATTERN_ORDER);
                    $matches_var = $matches_var[1];
                    $matches_val = $matches_val[1];
                    $matches_all = $matches_all[1];
                    for($i=0;$i<count($matches_var);$i++)
                    {
                        $field = $matches_var[$i];
                        $fallback = $matches_val[$i];
                        $tag = $matches_all[$i];

                        if(!isset($scheduling_tags[$field])) { $scheduling_tags[$field] = $scheduling_tag_counter++; }
                        $scheduling_tag_fallbacks[$field] = $fallback;

                        $html_treated = str_replace($tag, '<mxm var="field'.$scheduling_tags[$field].'" />', $html_treated);
                    } //  for($i=0;$i<count($matches_var);$i++) // html tags

                    preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $plain_treated, $matches_var, PREG_PATTERN_ORDER);
                    preg_match_all('/,\s*fallback=([a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*)\]/i', $plain_treated, $matches_val, PREG_PATTERN_ORDER);
                    preg_match_all('/(\[[a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+,\s*fallback=[a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*\])/i', $plain_treated, $matches_all, PREG_PATTERN_ORDER);
                    $matches_var = $matches_var[1];
                    $matches_val = $matches_val[1];
                    $matches_all = $matches_all[1];
                    for($i=0;$i<count($matches_var);$i++)
                    {
                        $field = $matches_var[$i];
                        $fallback = $matches_val[$i];
                        $tag = $matches_all[$i];

                        if(!isset($scheduling_tags[$field])) { $scheduling_tags[$field] = $scheduling_tag_counter++; }
                        $scheduling_tag_fallbacks[$field] = $fallback;

                        $plain_treated = str_replace($tag, '<mxm var="field'.$scheduling_tags[$field].'" />', $plain_treated);
                    } //  for($i=0;$i<count($matches_var);$i++) // plain text tags

                    //convert date tags
                    if($timezone!='' && $timezone!='0') date_default_timezone_set($timezone);
                    $today = time();
                    $currentdaynumber = strftime('%d', $today);
                    $currentday = strftime('%A', $today);
                    $currentmonthnumber = strftime('%m', $today);
                    $currentmonth = strftime('%B', $today);
                    $currentyear = strftime('%Y', $today);
                    $unconverted_date = array('[currentdaynumber]', '[currentday]', '[currentmonthnumber]', '[currentmonth]', '[currentyear]');
                    $converted_date = array($currentdaynumber, $currentday, $currentmonthnumber, $currentmonth, $currentyear);
                    $html_treated = str_replace($unconverted_date, $converted_date, $html_treated);
                    $plain_treated = str_replace($unconverted_date, $converted_date, $plain_treated);
                    $title_treated = str_replace($unconverted_date, $converted_date, $title_treated);

                    $scheduling_email_content = array('subject'=> $title_treated, 'html'=> $html_treated , 'text' => $plain_treated);
                    $scheduling_email_parameters = array('tag'=>array('sendy'), 'mailfrom'=> $from_email, 'mailfrom_friendly'=> $from_name,
                        'replyto'=>$reply_to, 'replyto_filtered'=> 'true');

                    //default values reset
                    $subscriber_id = '';
                    $email = '';
                    $subscriber_list = '';
                    while($row = mysqli_fetch_array($r))
                    {
                        //prevent execution timeout
                        set_time_limit(0);

                        $subscriber_id = $row['id'];
                        $name = trim($row['name']);
                        $email = trim($row['email']);
                        $subscriber_list = $row['list'];
                        $custom_values = $row['custom_fields'];

                        $scheduling_this_email = array();
                        $scheduling_this_email['email'] = $email;
                        $scheduling_this_email['field1'] = short($email);
                        $scheduling_this_email['field2'] = short($subscriber_list);
                        $scheduling_this_email['field3'] = short($subscriber_id);


                        //  replace all tags and custom fields
                        preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $title, $matches_subject, PREG_PATTERN_ORDER);
                        preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $html, $matches_html, PREG_PATTERN_ORDER);
                        preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $plain_text, $matches_plain, PREG_PATTERN_ORDER);
                        $matches_var = array_unique(array_merge($matches_subject[1], $matches_html[1], $matches_plain[1]));
                        for($i=0;$i<count($matches_var);$i++)
                        {
                            $field = $matches_var[$i];

                            //if tag is Name
                            if($field=='Name')
                            {
                                if($name=='')
                                    $scheduling_this_email['field'.$scheduling_tags[$field]] = $scheduling_tag_fallbacks[$field];
                                else
                                    $scheduling_this_email['field'.$scheduling_tags[$field]] = $row[strtolower($field)];
                            }
                            else //if not 'Name', it's a custom field
                            {
                                //if subscriber has no custom fields, use fallback
                                if($custom_values=='')
                                    $scheduling_this_email['field'.$scheduling_tags[$field]] = $scheduling_tag_fallbacks[$field];
                                //otherwise, replace custom field tag
                                else
                                {
                                    $q5 = 'SELECT custom_fields FROM lists WHERE id = '.$subscriber_list;
                                    $r5 = mysqli_query($mysqli, $q5);
                                    if ($r5)
                                    {
                                        while($row2 = mysqli_fetch_array($r5)) $custom_fields = $row2['custom_fields'];
                                        $custom_fields_array = explode('%s%', $custom_fields);
                                        $custom_values_array = explode('%s%', $custom_values);
                                        $cf_count = count($custom_fields_array);
                                        $k = 0;

                                        for($j=0;$j<$cf_count;$j++)
                                        {
                                            $cf_array = explode(':', $custom_fields_array[$j]);
                                            $key = str_replace(' ', '', $cf_array[0]);

                                            //if tag matches a custom field
                                            if($field==$key)
                                            {
                                                //if custom field is empty, use fallback
                                                if($custom_values_array[$j]=='')
                                                    $scheduling_this_email['field'.$scheduling_tags[$field]] = $scheduling_tag_fallbacks[$field];
                                                //otherwise, use the custom field value
                                                else
                                                {
                                                    //if custom field is of 'Date' type, format the date
                                                    if($cf_array[1]=='Date')
                                                        $scheduling_this_email['field'.$scheduling_tags[$field]] = strftime("%a, %b %d, %Y", $custom_values_array[$j]);
                                                    //otherwise just replace tag with custom field value
                                                    else
                                                        $scheduling_this_email['field'.$scheduling_tags[$field]] = $custom_values_array[$j];
                                                }
                                            }
                                            else
                                                $k++;
                                        }
                                        if($k==$cf_count)
                                            $scheduling_this_email['field'.$scheduling_tags[$field]] = $scheduling_tag_fallbacks[$field];
                                    }
                                }
                            }
                        } //  for($i=0;$i<count($matches_var);$i++) // replace all tags and custom fields

                        $scheduling_emails[$scheduling_email_counter] = $scheduling_this_email;
                        $scheduling_email_counter += 1;
                        if($scheduling_critsend_max_emails_per_transmission == $scheduling_email_counter) {
                            scheduling_critsend_send_emails($scheduling_email_content, $scheduling_email_parameters, $scheduling_emails);
                            $scheduling_email_counter = 0;
                            $scheduling_emails = array();
                        }

                        //increment recipient count if not using AWS or SMTP
                        if($s3_key=='' && $s3_secret=='')
                        {
                            //increment recipients number in campaigns table
                            $q5 = 'UPDATE campaigns SET recipients = recipients+1 WHERE id = '.$campaign_id;
                            mysqli_query($mysqli, $q5);

                            //update last_campaign
                            $q14 = 'UPDATE subscribers SET last_campaign = '.$campaign_id.' WHERE id = '.$subscriber_id;
                            mysqli_query($mysqli, $q14);
                        }
                    } // while($row = mysqli_fetch_array($r)) // send to each recipient
                    if(0 < $scheduling_email_counter) {
                        scheduling_critsend_send_emails($scheduling_email_content, $scheduling_email_parameters, $scheduling_emails);
                        $scheduling_email_counter = 0;
                        $scheduling_emails = array();
                    }

                    //Get server path
                    $server_path_array = explode('scheduled.php', $_SERVER['SCRIPT_FILENAME']);
                    $server_path = $server_path_array[0];

                    //====================== Send remaining in queue ======================//
                    $q4 = 'SELECT id, query_str, subscriber_id FROM queue WHERE campaign_id = '.$campaign_id.' AND sent = 0';
                    $r4 = mysqli_query($mysqli, $q4);
                    if ($r4 && mysqli_num_rows($r4) > 0)
                    {
                        while($row = mysqli_fetch_array($r4))
                        {
                            $request_url = 'https://'.$ses_endpoint;
                            $queue_id = $row['id'];
                            $query_str = stripslashes($row['query_str']);
                            $subscriber_id = $row['subscriber_id'];

                            //send remaining in queue
                            $cr = curl_init();
                            curl_setopt($cr, CURLOPT_URL, $request_url);
                            curl_setopt($cr, CURLOPT_POST, $query_str);
                            curl_setopt($cr, CURLOPT_POSTFIELDS, $query_str);
                            curl_setopt($cr, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($cr, CURLOPT_HEADER, TRUE);
                            curl_setopt($cr, CURLOPT_RETURNTRANSFER, TRUE);
                            curl_setopt($cr, CURLOPT_SSL_VERIFYHOST, 2);
                            curl_setopt($cr, CURLOPT_SSL_VERIFYPEER, 1);
                            curl_setopt($cr, CURLOPT_CAINFO, $server_path.'certs/cacert.pem');

                            // Make the request and fetch response.
                            $response = curl_exec($cr);

                            //Get message ID from response
                            $messageIDArray = explode('<MessageId>', $response);
                            $messageIDArray2 = explode('</MessageId>', $messageIDArray[1]);
                            $messageID = $messageIDArray2[0];

                            $response_http_status_code = curl_getinfo($cr, CURLINFO_HTTP_CODE);

                            if ($response_http_status_code !== 200)
                            {
                                $q7 = 'SELECT errors FROM campaigns WHERE id = '.$campaign_id;
                                $r7 = mysqli_query($mysqli, $q7);
                                if ($r7)
                                {
                                    while($row = mysqli_fetch_array($r7))
                                    {
                                        $errors = $row['errors'];

                                        if($errors=='')
                                            $val = $subscriber_id.':'.$response_http_status_code;
                                        else
                                        {
                                            $errors .= ','.$subscriber_id.':'.$response_http_status_code;
                                            $val = $errors;
                                        }
                                    }
                                }

                                //update campaigns' errors column
                                $q6 = 'UPDATE campaigns SET errors = "'.$val.'" WHERE id = '.$campaign_id;
                                mysqli_query($mysqli, $q6);
                            }
                            else
                            {
                                //increment recipients number in campaigns table
                                $q6 = 'UPDATE campaigns SET recipients = recipients+1 WHERE recipients < to_send AND id = '.$campaign_id;
                                mysqli_query($mysqli, $q6);

                                //update record in queue
                                $q5 = 'UPDATE queue SET sent = 1, query_str = NULL WHERE id = '.$queue_id;
                                mysqli_query($mysqli, $q5);

                                //update messageID of subscriber
                                $q14 = 'UPDATE subscribers SET messageID = "'.$messageID.'" WHERE id = '.$subscriber_id;
                                mysqli_query($mysqli, $q14);
                            }
                        }
                    }
                    else
                    {
                        $q12 = 'UPDATE campaigns SET to_send = (SELECT recipients) WHERE id = '.$campaign_id;
                        $r12 = mysqli_query($mysqli, $q12);
                        if ($r12)
                        {
                            $q13 = 'SELECT recipients FROM campaigns WHERE id = '.$campaign_id;
                            $r13 = mysqli_query($mysqli, $q13);
                            if ($r13) while($row = mysqli_fetch_array($r13)) $current_recipient_count = $row['recipients'];
                            $to_send = $current_recipient_count;
                            $to_send_num = $current_recipient_count;
                        }
                    }
                    //======================= /Send remaining in queue ======================//
                }
                else
                {
                    $q12 = 'UPDATE campaigns SET to_send = '.$current_recipient_count.' WHERE id = '.$campaign_id;
                    $r12 = mysqli_query($mysqli, $q12);
                    if ($r12)
                    {
                        $to_send = $current_recipient_count;
                        $to_send_num = $current_recipient_count;
                    }
                }

                //=========================== Post processing ===========================//

                $q8 = 'SELECT recipients FROM campaigns where id = '.$campaign_id;
                $r8 = mysqli_query($mysqli, $q8);
                if ($r8) while($row = mysqli_fetch_array($r8)) $no_of_recipients = $row['recipients'];
                if($no_of_recipients >= $to_send)
                {
                    //tags for subject to me
                    preg_match_all('/\[([a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+),\s*fallback=/i', $title, $matches_var, PREG_PATTERN_ORDER);
                    preg_match_all('/,\s*fallback=([a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*)\]/i', $title, $matches_val, PREG_PATTERN_ORDER);
                    preg_match_all('/(\[[a-zA-Z0-9!#%^&*()+=$@._\-\:|\/?<>~`"\'\s]+,\s*fallback=[a-zA-Z0-9!,#%^&*()+=$@._\-\:|\/?<>~`"\'\s]*\])/i', $title, $matches_all, PREG_PATTERN_ORDER);
                    $matches_var = $matches_var[1];
                    $matches_val = $matches_val[1];
                    $matches_all = $matches_all[1];
                    for($i=0;$i<count($matches_var);$i++)
                    {
                        $field = $matches_var[$i];
                        $fallback = $matches_val[$i];
                        $tag = $matches_all[$i];
                        //for each match, replace tag with fallback
                        $title = str_replace($tag, $fallback, $title);
                    }
                    $title = str_replace('[Email]', $from_email, $title);
                    $title = str_replace($unconverted_date, $converted_date, $title);

                    $title_to_me = '['._('Campaign sent').'] '.$title;

                    $app_path = APP_PATH;

                    if($to_send_num=='' || $to_send>$to_send_num) $to_send_num = $to_send;

                    $message_to_me_plain = _('Your campaign has been successfully sent to')." $to_send_num "._('recipients')."!

            "._('View report')." - $app_path/report?i=$app&c=$campaign_id";

                    $message_to_me_html = "
                                <div style=\"margin: -10px -10px; padding:50px 30px 50px 30px; height:100%;\">
                                    <div style=\"margin:0 auto; max-width:660px;\">
                                        <div style=\"float: left; background-color: #FFFFFF; padding:10px 30px 10px 30px; border: 1px solid #DDDDDD;\">
                                            <div style=\"float: left; max-width: 106px; margin: 10px 20px 15px 0;\">
                                                <img src=\"$app_path/img/email-icon.gif\" style=\"width: 100px;\"/>
                                            </div>
                                            <div style=\"float: left; max-width:470px;\">
                                                <p style=\"line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;\">
                                                    <strong style=\"line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 18px;\">"._('Your campaign has been sent')."!</strong>
                                                </p>
                                                <div style=\"line-height: 21px; min-height: 100px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;\">
                                                    <p style=\"line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;\">"._('Your campaign has been successfully sent to')." $to_send_num "._('recipients')."!</p>
                                                    <p style=\"line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px; margin-bottom: 25px; background-color:#EDEDED; padding: 15px;\">
                                                        <strong>"._('Campaign').": </strong>$title<br/>
                                                        <strong>"._('Recipients').": </strong>$to_send_num<br/>
                                                        <strong>"._('View report').": </strong><a style=\"color:#4371AB; text-decoration:none;\" href=\"$app_path/report?i=$app&c=$campaign_id\">$app_path/report?i=$app&c=$campaign_id</a>
                                                    </p>
                                                    <p style=\"line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;\">
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                ";

                    $q4 = 'UPDATE campaigns SET recipients = '.$to_send_num.' WHERE id = '.$campaign_id;
                    mysqli_query($mysqli, $q4);

                    $q9 = 'DELETE FROM queue WHERE campaign_id = '.$campaign_id;
                    mysqli_query($mysqli, $q9);

                    $q11 = 'SELECT errors, to_send_lists FROM campaigns WHERE id = '.$campaign_id;
                    $r11 = mysqli_query($mysqli, $q11);
                    if ($r11)
                    {
                        while($row = mysqli_fetch_array($r11))
                        {
                            $error_recipients_ids = $row['errors'];
                            $tsl = $row['to_send_lists'];
                        }

                        if($error_recipients_ids=='')
                        {
                            $q10 = 'UPDATE subscribers SET bounce_soft = 0 WHERE list IN ('.$tsl.')';
                            mysqli_query($mysqli, $q10);
                        }
                        else
                        {
                            $error_recipients_ids_array = explode(',', $error_recipients_ids);
                            $eid_array = array();
                            foreach($error_recipients_ids_array as $id_val)
                            {
                                $id_val_array = explode(':', $id_val);
                                array_push($eid_array, $id_val_array[0]);
                            }
                            $error_recipients_ids = implode(',', $eid_array);
                            $q10 = 'UPDATE subscribers SET bounce_soft = 0 WHERE list IN ('.$tsl.') AND id NOT IN ('.$error_recipients_ids.')';
                            mysqli_query($mysqli, $q10);
                        }
                    }

                    //send email to sender
                    $content = array('subject'=> $title_to_me, 'html'=> $message_to_me_html , 'text' => $message_to_me_plain);
                    $scheduling_email_parameters['tag'] = array('sendy-automatic');
                    $emails = array();
                    $emails[0] = array('email'=>$from_email);
                    $emails[1] = array('email'=>$user_email);
                    scheduling_critsend_send_emails($content, $scheduling_email_parameters, $emails);

                } // if($no_of_recipients >= $to_send)

                //========================== /Post processing ===========================//
            } // if((($time>=$send_date && $time<$send_date+300) && $sent=='') || (($send_date<$time) && $sent=='') || ($send_date=='0' && $timezone=='0'))
        } //while($row = mysqli_fetch_array($r)) // sorting through campaigns to send
    } // if ($r && mysqli_num_rows($r) > 0) // there are campaigns to send
} // function scheduling_critsend($row)

function scheduling_critsend_send_emails($content, $param, $emails) {
    global $mxm;
    try {
        $mxm->sendCampaign($content, $param, $emails);
    } catch (MxmException $e) {
        echo $e->getMessage();
    }
} // scheduling_critsend_send_emails($content, $param, $emails)
?>