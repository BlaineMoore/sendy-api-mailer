<?php
/*
 * File: scheduling/includes/scheduling-config.php
 * Description: This file includes other required files and provides an optional place for settings.
 * Version: 0.1
 * Contributors:
 *      Blaine Moore    http://blainemoore.com
 *
 * Suggestion:
 *      Copy the variables below to the standard sendy includes/config.php file and set them there.
 *      That way you can update the scheduling files from git without losing your settings and the
 *      only place to worry about overwriting is a file that is already part of the normal Sendy
 *      update process.
 *
 * Warning:
 *      If this folder is placed anywhere other than at the root of the Sendy installation,
 *      or if you rename this folder so that it is not "scheduling", then be sure to update
 *      the compulsory settings below. If you do change that setting, then be sure not to
 *      overwrite this file when performing future updates.
 */

//----------------------------------------------------------------------------------//
//								  COMPULSORY SETTINGS
//----------------------------------------------------------------------------------//

    // This needs to point to the Sendy root using a relative path.
    // Only change this if you do not place the scheduling folder in the Sendy root folder
        define('SCHEDULING_RELATIVE_PATH_TO_ROOT','..');

    // This needs to be the name and path from the Sendy root to this folder.
    // Only change this if you do not place the scheduling folder in the Sendy root folder
    //  or if you change the name of this folder.
        define('SCHEDULING_PATH', '/scheduling');

    // Here is an example of placing this folder in a "custom" folder in the Sendy root:
        // define('SCHEDULING_RELATIVE_PATH_TO_ROOT','../..');
        // define('SCHEDULING_PATH', '/custom/scheduling')

//----------------------------------------------------------------------------------//


//----------------------------------------------------------------------------------//
//								  config.php SETTINGS
//----------------------------------------------------------------------------------//

    // I recommend copying these settings to the Sendy /includes/config.php file
    // Optionally, you can update the default values here.

    //if(!isset($scheduling_var))
    //    $scheduling_var = ""; // comment

//----------------------------------------------------------------------------------//

include_once(SCHEDULING_RELATIVE_PATH_TO_ROOT . '/includes/config.php');
include_once('db.php');
include_once(SCHEDULING_RELATIVE_PATH_TO_ROOT . '/includes/helpers/class.phpmailer.php');
include_once(SCHEDULING_RELATIVE_PATH_TO_ROOT . '/includes/helpers/short.php');
include_once(SCHEDULING_RELATIVE_PATH_TO_ROOT . '/includes/helpers/locale.php');
?>
