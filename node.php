<?php

/**
 * Node.php v0.5
 * (c) 2016 Jerzy Głowacki
 *     2016/7/21 Add getallheaders() for v5.3
 * MIT License
 */

//define("ADMIN_MODE", true);
define("ADMIN_MODE", false); //set to true to allow unsafe operations, set back to false when finished

//set to true for shared hosts that periodically kill user's processes (such as node)
define("RESTART_PROCESS", false);

error_reporting(E_ALL);

function getDistro()
{
	$OSDistro = array();
    if (strtolower(substr(PHP_OS, 0, 3)) === 'lin')
		$OSDistro = ['-linux-', '.tar.gz'];
	elseif (strtolower(substr(PHP_OS, 0, 3)) === 'win')
		$OSDistro = ['-win-', '.zip'];
	elseif (strtolower(substr(PHP_OS, 0, 3)) === 'dar')
		$OSDistro = ['-darwin-', '.tar.gz'];
	else
		$OSDistro = ['-'.strtolower(substr(PHP_OS, 0, 5)).'-', '.tar.gz'];
	return $OSDistro;
}
$OSDistro = getDistro();
define( '_DS', DIRECTORY_SEPARATOR );

define("NODE_VER", "v10.11.0");
define("NODE_OS", $OSDistro[0]);
define("NODE_ARCH", "x" . substr(php_uname("m"), -2)); //x86 or x64
define("NODE_FILE", "node-" . NODE_VER . NODE_OS . NODE_ARCH . $OSDistro[1]);
define("NODE_FOLDER", "node-" . NODE_VER . NODE_OS . NODE_ARCH);
define("NODE_URL", "http://nodejs.org/dist/" . NODE_VER . "/" . NODE_FILE);
define("NODE_DIR", __DIR__. _DS. "node");
define("NODE_PORT", 49999);

if (!function_exists('getallheaders')) 
{ 
    function getallheaders() 
    { 
           $headers = ''; 
       foreach ($_SERVER as $name => $value) 
       { 
           if (substr($name, 0, 5) == 'HTTP_') 
           { 
               $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; 
           } 
       } 
       return $headers; 
    } 
} 

function recurse_copy($src, $dst) 
{ 
    if ( ! is_dir($src) )
        return false;
    $dir = opendir($src); 
    @mkdir($dst); 
    while(false !== ( $file = readdir($dir)) ) { 
        if (( $file != '.' ) && ( $file != '..' )) { 
            if ( is_dir($src . _DS . $file) ) { 
                recurse_copy($src._DS.$file, $dst._DS.$file); 
            } 
            else { 
                copy($src._DS.$file, $dst._DS.$file); 
            } 
        } 
    } 
    closedir($dir); 
    return true;
} 

function recurse_delete($directory, $options = array()) 
{
    if(!isset($options['traverseSymlinks']))
        $options['traverseSymlinks'] = false;
    $files = array_diff(scandir($directory), array('.', '..'));
    foreach ($files as $file)
    {
        $dirfile = $directory._DS.$file;
        if (is_dir($dirfile))
        {
            if(!$options['traverseSymlinks'] && is_link(rtrim($file, _DS))) {
                unlink($dirfile);
            } else {
                recurse_delete($dirfile, $options);
            }
        } else {
            unlink($dirfile);
        }
    }
    return rmdir($directory);
}

function node_install() 
{
	if(file_exists(NODE_DIR)) {
		echo "Node.js is already installed.<br>\n";
		return;
	}
	if(!file_exists(NODE_FILE)) {
		echo "Downloading Node.js from " . NODE_URL . ":<br>\n\n";
		$fp = fopen(NODE_FILE, "w");
		flock($fp, LOCK_EX);
		$curl = curl_init(NODE_URL);
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_setopt($curl, CURLOPT_FILE, $fp);
		$resp = curl_exec($curl);
		curl_close($curl);
		flock($fp, LOCK_UN);
		fclose($fp);
		echo $resp === true ? "Done.<br>\n" : "Failed. Error: curl_error($curl)<br>\n";
	}
	echo "\n<br>Installing Node.js:<br>\n";
	if (NODE_OS == '-win-')
	{
		$zip = new ZipArchive;
		$ret = $zip->open(NODE_FILE);
		if ($ret === TRUE) {
			$zip->extractTo('.');
			$zip->close();
		}
	} else {
		// decompress from gz
		$p = new PharData(NODE_FILE);
		$p->decompress(); // creates *.tar

		// unarchive from the tar
		$archive = new PharData(str_replace(NODE_FILE, 'node-v10.tar', NODE_FILE));
		$archive->extractTo('.');
		unlink('node-v10.tar');

		//passthru("tar -xzf " . NODE_FILE . " 2>&1 && mv " . NODE_FOLDER . " " . NODE_DIR . " && touch nodepid && rm -f " . NODE_FILE, $ret);
	}
	if (recurse_copy(NODE_FOLDER, NODE_DIR)) 
		$ret = touch('nodepid');
	recurse_delete(NODE_FOLDER);
	echo (!$ret) ? "Done.\n" : "Failed. Error: $ret\nTry putting node folder via (S)FTP, so that " . __DIR__ . "/node/bin/node exists.";
}

function node_uninstall() 
{
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed.\n";
		return;
	}
	echo "Unnstalling Node.js:\n";
	
	$ret = recurse_delete(NODE_DIR);
	unlink('nodepid');
	echo ($ret) ? "Done.\n" : "Failed. Error: $ret\n";
}

function node_start($file) 
{
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid > 0) {
		echo "Node.js is already running. <a href='?stop'>Stop it</a>.\n";
		return;
	}
	$file = escapeshellarg($file);
	echo "Starting: node $file\n";

	$node_pid = run($file,	NODE_DIR, ['PORT' => NODE_PORT]);
	echo $node_pid > 0 ? "Done. PID=$node_pid\n" : "Failed.\n";

	file_put_contents("nodepid", $node_pid, LOCK_EX);	
	if ($node_pid>0) {
		file_put_contents('nodestart', $file, LOCK_EX);
	}
	sleep(1); //Wait for node to spin up
}

function kill($pid)
{
    return (NODE_OS == '-win-')  ? exec("taskkill /F /T /PID $pid") : exec("kill -9 $pid");
}

function run($cmd, $startDir = null, $env = array())
{
	if(NODE_OS == '-win-') {  
		$descriptorspec = array (
			0 => array("pipe", "r"),  
			1 => array("pipe", "w"),  
		);  
		
		//proc_open — Execute a command  
		//'start /b' runs command in the background  
		if ( is_resource( $prog = proc_open('start /b node '.$cmd, $descriptorspec, $pipes, $startDir, $env) ) ) {  
			//Get Parent process Id  
			$ppid = proc_get_status($prog);  
			$pid = $ppid['pid'];  
		} else {  
			echo("Failed to execute!");  
			exit();  
		}  

		$output = array_filter(explode(" ", shell_exec("wmic process get parentprocessid,processid | find \"$pid\"")));  
		array_pop($output);  
		
		$pid = end($output);  
	} else {  
		$descriptorspec = array (  
			0 => array("pipe", "r"),  
			1 => array("pipe", "w"),  
		);  
	  
		//proc_open — Execute a command  
		//'nohup' command line-utility will allow you to run command/process or shell script that can continue running in the background  
		if (is_resource($prog = proc_open('nohup bin'._DS.'node '.$cmd, $descriptorspec, $pipes, $startDir, $env) ) ) {  
			//Get Parent process Id   
			$ppid = proc_get_status($prog);  
			$pid = $ppid['pid'];  
			
			$pid = $pid + 1;  		
		} else {  
			echo("Failed to execute!");  
			exit();  
		}  
	}  
    return $pid;
}

function node_stop() 
{
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid === 0) {
		echo "Node.js is not yet running.\n";
		return;
	}
	echo "Stopping Node.js with PID=$node_pid:\n";
	$ret = -1;
	$ret = kill($node_pid);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret\n";
	file_put_contents("nodepid", '', LOCK_EX);
}

function node_npm($cmd) 
{
	if(!file_exists(NODE_DIR)) {
		echo "Node.js is not yet installed. <a href='?install'>Install it</a>.\n";
		return;
	}
	$cmd = escapeshellcmd(NODE_DIR . "/bin/npm --cache ./.npm -- $cmd");
	echo "Running: $cmd\n";
	$ret = -1;
	passthru($cmd, $ret);
	echo $ret === 0 ? "Done.\n" : "Failed. Error: $ret. See <a href=\"npm-debug.log\">npm-debug.log</a>\n";
}

function node_serve($path = "") 
{
	if (!file_exists(NODE_DIR)) {
		node_head();
		echo "Node.js is not yet installed. Switch to Admin Mode and <a href='?install'>Install it</a>.\n";
		node_foot();
		return;
	} elseif ($RESTART_PROCESS && $node_pid && !posix_getpgid($node_pid)) {
		$nodestart = file_get_contents('nodestart');
		if($nodestart){
			node_start($nodestart);
			//wait for node process to start, then retry to node_serve
			sleep(5);
			node_serve($path);
			return;
		}
		echo "Please switch to Admin Mode and manually restart the server. <a href='?start'>Start it</a>\n";
		return;		
	}

	$node_pid = intval(file_get_contents("nodepid"));
	if($node_pid === 0) {
		node_head();
		echo "Node.js is not yet running. Switch to Admin Mode and <a href='?start'>Start it</a>\n";
		node_foot();
		return;
	}
        $url = "http://127.0.0.1:" . NODE_PORT . "/$path";
        //header('HTTP/1.1 307 Temporary Redirect');
        //header("Location: $url");
	
        $curl = curl_init($url);
	curl_setopt($curl, CURLOPT_HEADER, 1);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $headers = array();
        foreach(getallheaders() as $key => $value) {
                $headers[] = $key . ": " . $value;
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
        if($_SERVER["REQUEST_METHOD"] === "POST") {
            curl_setopt($curl, CURLOPT_POST, 1);
            if (count($_POST)==0) {  //strlen($str_json_params) > 0) && isValidJSON($json_params)) {
                $str_json_params = file_get_contents('php://input');
                curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($curl, CURLOPT_POSTFIELDS, $str_json_params);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            }else{
                //$str_header = implode(",", $headers);
                $fields = http_build_query($_POST);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
                //error_log("post json=$str_json_params ");
            }
        } else if($_SERVER["REQUEST_METHOD"] === "PUT" || $_SERVER["REQUEST_METHOD"] === "DELETE"){
	    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $_SERVER["REQUEST_METHOD"]);
            curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	}
        //error_log("url=$url");
 	$resp = curl_exec($curl);
	if($resp === false) {
		node_head();
		echo "Error requesting $path: " . curl_error($curl);
		node_foot();
	} else {
		list($head, $body) = explode("\r\n\r\n", $resp, 2);
		$headarr = explode("\n", $head);
		foreach($headarr as $headval) {
			header($headval);
		}
		echo $body;
	}
	curl_close($curl);
}

function node_head() 
{
	echo '<!DOCTYPE html><html><head><title>Node.php</title><meta charset="utf-8"><body style="font-family:Helvetica,sans-serif;"><h1>Node.php</h1><pre>';
}

function node_foot() 
{
	echo '</pre><p><a href="https://github.com/niutech/node.php" target="_blank">Powered by node.php</a></p></body></html>';
}

function node_dispatch() 
{
	$checkScript = (realpath($_SERVER['argv'][0]) == __FILE__);
	$checkArgs = isset($_SERVER['argv'][1]);
	$checkAdmin = ($checkScript && $checkArgs) && ($_SERVER['argv'][1] == '--admin');
	$getCommand = (($checkScript && $checkArgs) && !$checkAdmin) ? $_SERVER['argv'][1] : (($checkAdmin) ? $_SERVER['argv'][2] : '');
	if(ADMIN_MODE || $checkAdmin) {
		node_head();
		if (isset($_GET['install']) || ($getCommand == 'install')){
			node_install();
		} elseif (isset($_GET['uninstall']) || ($getCommand == 'uninstall')) {
			node_uninstall();
		} elseif (isset($_GET['start']) || ($getCommand == 'start')) {
			node_start($_GET['start']);
		} elseif (isset($_GET['stop']) || ($getCommand == 'stop')) {
			node_stop();
		} elseif (isset($_GET['npm']) || ($getCommand == 'npm')) {
			if (empty($getCommand))
				node_npm($_GET['npm']);
			else {
				$getCommand = $_SERVER['argv'];	
				unset($getCommand[0]);
				unset($getCommand[1]);
				node_npm($getCommand);
			}				
		} else {
			echo "You are in Admin Mode. Switch back to normal mode to serve your node app.";
		}
		node_foot();
	} elseif (isset($_SERVER['REQUEST_URI'])) {
		$full_url = $_SERVER['REQUEST_URI'];
		$path = explode("?path=",$full_url);
		node_serve($path[1]);
	} elseif (isset($getCommand)) {
		node_serve($getCommand);
	}
}

node_dispatch();
