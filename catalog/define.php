<?php
	ob_start();
	ini_set('default_charset','UTF-8');
	header('Content-Type: text/html; charset=utf-8');
	if (!isset($_SESSION)) session_start();
	if (isset($_GET['d'])) 
		$_SESSION['debug'] = ( $_GET['d'] == 1 ); 
	else {
		if (!isset($_SESSION['debug'])) $_SESSION['debug'] = false;
		$_GET['d'] = ($_SESSION['debug']?'1':'0');
	}
	define("DBHOST", "localhost");
	define("DBUSER", "csurgay_tintin");
	define("DBPASS", "Tintin11"); 
	define("DBNAME", "csurgay_tintin");
	// $dbconn = mysqli_connect(DBHOST, DBUSER, DBPASS, DBNAME);
	$dbconn = mysqli_connect(DBHOST, DBUSER, DBPASS, DBNAME);
	if ($dbconn === false) $dbconn = @mysqli_connect(DBHOST, DBUSER, DBPASS) or die("Cannot connect to database!"); 

//	printf("Initial character set: %s\n", $dbconn->character_set_name());
	if (!$dbconn->set_charset("utf8")) {
//		printf("Error loading character set utf8: %s\n", $dbconn->error);
		exit();
	} else {
//		printf("Current character set: %s\n", $dbconn->character_set_name());
	}	
/*
	mysqli_query($dbconn, "SET character_set_connection = 'utf8'"); 
	mysqli_query($dbconn, "SET character_set_client = 'utf8'"); 
	mysqli_query($dbconn, "SET character_set_results = 'utf8'");
	mysqli_query($dbconn, "SET COLLATION_CONNECTION = 'utf8'");
*/
	mysqli_query($dbconn, "SET NAMES utf8");

	// mysqli_query($dbconn, "set character set 'utf8'");
	// mysqli_set_charset($dbconn, "utf8");
?>
