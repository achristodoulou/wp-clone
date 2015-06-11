<?php
/**
 * WordPress cloning script
 */

$root_directory = '/home/achristodoulou/Projects/test/wordpress/';
$wp_directory = 'www/';
$wp_clone_directory_name = 'staging/';
$wp_clone_directory_path = $root_directory . $wp_directory . $wp_clone_directory_name;

#Load wp-config
$wp_config = $root_directory . $wp_directory . 'wp-config.php';
include $wp_config;

#DB Credentials
$db_host = DB_HOST;
$db_user = DB_USER;
$db_name = DB_NAME;
$dp_pass = DB_PASSWORD;
$db_clone_name = DB_NAME . '_staging';

#File Permissions
$wp_owner = posix_getpwuid(fileowner($wp_config))['name'];
$wp_group = posix_getgrgid(filegroup($wp_config))['name'];

#Temporary archive filename
$archive_name = 'clone.zip';

$connection = null;

try
{
    #Starting timer
    $time_start = microtime(true);

    #TODO Check if clone folder already exists

    #Archive the current directory
    zip($archive_name, $root_directory . $wp_directory);
    echo "<br />Archive created ($archive_name, $root_directory$wp_directory)";

    #Exctract the archive directory
    unzip($archive_name, $root_directory . $wp_directory);
    echo "<br />Archive extracted ($archive_name, $root_directory$wp_directory)";

    #Rename the clone directory
    rename($root_directory.$wp_directory.$wp_directory, $wp_clone_directory_path);
    echo "<br />Rename clone directory ($root_directory$wp_directory$wp_directory, $wp_clone_directory_path)";

    #Connect to db
    $connection = open_db_connection($db_host, $db_user, $dp_pass);
    echo "<br />DB connection established ($db_host, $db_user, $dp_pass)";

    #Clone wp db
    clone_db($connection, $db_name, $db_clone_name);
    echo "<br />DB cloning completed ($db_name, $db_clone_name)";

    #Update db urls
    update_db($connection, $db_clone_name, $wp_clone_directory_name);
    echo "<br />DB updated ($db_clone_name, $wp_clone_directory_name)";

    #Update wp-config.php to point to new db
    update_wp_config($db_name, $db_clone_name, $wp_clone_directory_path);
    echo "<br />WP config updated ($db_name, $db_clone_name, $wp_clone_directory_path)";

    #Set wp directories proper permissions
    fix_wp_file_permissions($wp_clone_directory_path, $wp_owner, $wp_group);
    echo "<br />Fixed Files and Folders permissions ($wp_clone_directory_path, $wp_owner, $wp_group)";

    #Disable crawlers to index the clone site

    #Stop timer
    $time_end = microtime(true);
    $time = $time_end - $time_start;

    #Cloning process completed
    $protocol = $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://';
    echo "<br />Cloning process has been completed!!! <a target='_blank' href='" . $protocol . $_SERVER['HTTP_HOST'] . '/' . $wp_clone_directory_name . "'>Go to clone site and enjoy :)</a>";
    echo "<br /><i>Script execution took $time seconds</i>";
}
catch (Exception $e)
{
    if(file_exists($root_directory.$wp_directory.$wp_directory)) {
        #Remove clone directory if failed to be renamed
        rmdir($root_directory.$wp_directory.$wp_directory);
    }

    if(file_exists($wp_clone_directory_path)) {
        #Remove clone dir
        rmdir($wp_clone_directory_path);
    }

    if($connection !== null)
        drop_clone_database($connection, $db_clone_name);

    echo "<br />An error occurred, rolled back all changes! " . $e->getMessage();
}
finally
{
    if(file_exists($archive_name)) {
        #Remove archive file
        unlink($archive_name);
    }

    if($connection !== null)
        $connection->close();
}

#####################################################################################

/**
 * Update wp clone config
 *
 * @param $db_name
 * @param $db_clone_name
 * @param $wp_clone_directory_path
 */
function update_wp_config($db_name, $db_clone_name, $wp_clone_directory_path)
{
    $wp_config = $wp_clone_directory_path . 'wp-config.php';
    $file_contents = file_get_contents($wp_config);
    $file_contents = str_replace("define('DB_NAME', '$db_name');", "define('DB_NAME', '$db_clone_name');",$file_contents);
    file_put_contents($wp_config, $file_contents);
}

/**
 * Open db connection
 *
 * @param $host
 * @param $user
 * @param $password
 * @return mysqli
 */
function open_db_connection($host, $user, $password)
{
    // Create connection
    $conn = new mysqli($host, $user, $password);
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

function drop_clone_database(Mysqli $resource, $db_clone_name)
{
    @mysqli_select_db ( $resource, $db_clone_name );
    $resource->query("DROP DATABASE IF EXISTS $db_clone_name");
}

/**
 * Clone wp db
 *
 * @param Mysqli $resource
 * @param $db_name
 * @param $db_clone_name
 * @throws Exception
 */
function clone_db(Mysqli $resource, $db_name, $db_clone_name)
{
    $error = true;

    @mysqli_select_db ( $resource, $db_name );
    $getTables = $resource->query("SHOW TABLES");
    $tables = array();
    while($row = mysqli_fetch_row($getTables)){
        $tables[] = $row[0];
    }

    #Create clone db
    mysqli_query($resource, "CREATE DATABASE `$db_clone_name` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;")
    or function(){ throw new Exception(mysql_error()); };

    foreach($tables as $cTable){
        @mysqli_select_db ( $resource, $db_clone_name );
        $create     =   $resource->query("CREATE TABLE $cTable LIKE ".$db_name.".".$cTable);
        if(!$create) {
            $error = false;
        }
        $resource->query("INSERT INTO $cTable SELECT * FROM ".$db_name.".".$cTable);
    }

    if($error === false)
        throw new Exception('Database cloning failed!');
}

/**
 * Update clone db
 *
 * @param Mysqli $resource
 * @param $db_clone_name
 * @param $wp_clone_directory
 */
function update_db(Mysqli $resource, $db_clone_name, $wp_clone_directory)
{
    @mysqli_select_db ( $resource, $db_clone_name );

    $sql_query1 = "UPDATE wp_options SET option_value = CONCAT(option_value, '/$wp_clone_directory') WHERE option_name = 'siteurl'";

    $sql_query2 = "UPDATE wp_options SET option_value = CONCAT(option_value, '/$wp_clone_directory') WHERE option_name = 'home'";

    $resource->query($sql_query1);
    $resource->query($sql_query2);
}

/**
 * Extract an archived directory
 *
 * @param $archive_file_name
 * @param $extract_dir
 * @throws Exception
 */
function unzip($archive_file_name, $extract_dir)
{
    $zip = new ZipArchive;
    if ($zip->open($archive_file_name) === TRUE) {
        $zip->extractTo($extract_dir);
        $zip->close();
    } else {
        throw new Exception('Unzip operation failed');
    }
}

/**
 * Archive a directory
 *
 * @param $archive_file_name
 * @param $dirName
 * @throws Exception
 */
function zip($archive_file_name, $dirName)
{
    $zip = new ZipArchive();
    $zip->open($archive_file_name, ZipArchive::CREATE);

    if (!is_dir($dirName)) {
        throw new Exception('Directory ' . $dirName . ' does not exist');
    }

    $dirName = realpath($dirName);
    if (substr($dirName, -1) != '/') {
        $dirName.= '/';
    }

    $dirStack = array($dirName);
    //Find the index where the last dir starts
    $cutFrom = strrpos(substr($dirName, 0, -1), '/')+1;

    while (!empty($dirStack)) {
        $currentDir = array_pop($dirStack);
        $filesToAdd = array();

        $dir = dir($currentDir);
        while (false !== ($node = $dir->read())) {
            if (($node == '..') || ($node == '.')) {
                continue;
            }
            if (is_dir($currentDir . $node)) {
                array_push($dirStack, $currentDir . $node . '/');
            }
            if (is_file($currentDir . $node)) {
                $filesToAdd[] = $node;
            }
        }

        $localDir = substr($currentDir, $cutFrom);
        $zip->addEmptyDir($localDir);

        foreach ($filesToAdd as $file) {
            $zip->addFile($currentDir . $file, $localDir . $file);
        }
    }

    $zip->close();
}

/**
 * Configures wp file permissions based on recommendations
 * @see http://codex.wordpress.org/Hardening_WordPress#File_permissions
 *
 * @param $wp_clone_directory_path
 * @param $wp_owner
 * @param $wp_group
 * @internal param $web_server_group
 */
function fix_wp_file_permissions($wp_clone_directory_path, $wp_owner, $wp_group)
{

    # reset to safe defaults
    $commands = "find ${wp_clone_directory_path} -exec chown ${wp_owner}:${wp_group} {} \\;
                 find ${wp_clone_directory_path} -type d -exec chmod 755  {} \\;
                 find ${wp_clone_directory_path} -type f -exec chmod 644  {} \\;
    ";

    # allow WP to manage wp-config.php (but prevent world access)
    $commands .= "chgrp ${$wp_group} ${wp_clone_directory_path}wp-config.php \\;
                  chmod 660 ${wp_clone_directory_path}wp-config.php \\;
    ";

    # allow WP to manage wp-content
    $commands .= "find ${wp_clone_directory_path}wp-content -exec chgrp ${$wp_group}  {} \\;
                  find ${wp_clone_directory_path}wp-content -type d -exec chmod 775 {} \\;
                  find ${wp_clone_directory_path}wp-content -type f -exec chmod 664  {} \\;
    ";

    exec($commands, $output);
}