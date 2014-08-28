<?php
/*
 * File: scheduling/includes/db.php
 * Description: This file includes all database connections.
 * Version: 0.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 *
 */

function dbConnect() { //Connect to database
    // Access global variables
    global $mysqli;
    global $dbHost;
    global $dbUser;
    global $dbPass;
    global $dbName;
    global $dbPort;

    // Attempt to connect to database server
    if(isset($dbPort)) $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
    else $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

    // If connection failed...
    if ($mysqli->connect_error) {
        fail();
    }

    global $charset; mysqli_set_charset($mysqli, isset($charset) ? $charset : "utf8");

    return $mysqli;
}

function fail() { //Database connection fails
    print 'Database error';
    exit;
}

// connect to database
dbConnect();
?>