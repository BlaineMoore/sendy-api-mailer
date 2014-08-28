<?php
/*
 * File: scheduling/normal-sendy.php
 * Description: This file is for when no handler is found and we should use the standard Sendy scheduled.php functionality.
 * Version: 0.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 */

function scheduling_normal() {
    // send using standard scheduled.php functionality...
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, APP_PATH.'/scheduled.php');
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $data = curl_exec($ch);
    exit;
}
?>