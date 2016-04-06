<?php
// Enable Error reporting
ini_set('display_errors', -1);
error_reporting(E_ALL);

// Disable caching
header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() - 3600));

// Get script base url
$baseURL = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$baseURL = explode(basename(__FILE__), $baseURL);
$baseURL = current($baseURL);

// Disable execution time limit
set_time_limit(0);

class Installer
{
	/**
	 * CURL connection handle.
	 * 
	 * @var   Resource
	 */
	protected $connection = null;

	/**
	 * Gitbhub data cache
	 *
	 * @var   Array
	 */
	protected $cache = array(
		'versions' => array(),
		'latest' => ''
	);

	/**
	 * Load cache
	 */
	public function __construct()
	{

		// Load cache if exists and is fresh (not older then hour)
		$path = __DIR__.'/getjoomla.cache';
		if (
			file_exists($path) AND
			filemtime($path) > (time() - (60 * 15)) AND
			$buffer = json_decode(file_get_contents($path))
		) {
			$this->cache = $this->objectToArray($buffer);
		}
	}

	/**
	 * Converts object to multidimensional array
	 *
	 * @param   Object   $object   Object to conver
	 * 
	 * @return   Array
	 */
	protected function objectToArray($object)
	{
		$buffer = array();
		foreach ($object AS $variable => $data) {
			if (is_object($data)) {
				$buffer[$variable] = $this->objectToArray($data);
			} else {
				$buffer[$variable] = $data;
			}
		}
		return $buffer;
	}

	/**
	 * Get contents of a file via URL (http)
	 *
	 * @param   String   $url   URL of a file.
	 *
	 * @return   String
	 */
	protected function getURLContents($url){
		if( function_exists('curl_init') ) {
			
			// Prepre CURL connection
			$this->prepareConnection($url);

			// Return response
			$buffer = curl_exec ($this->connection);

		} else {
			$options = $file_get_contents_options = array(
				'ssl' => array(
					"verify_peer" => false,
					"verify_peer_name" => false
				),
				'http' => array(
					'user_agent' => $_SERVER['HTTP_USER_AGENT']
				)
			);
			
			$buffer = file_get_contents(
				$url, false,
				stream_context_create($options)
			);
		}

		return $buffer;
	}

	/**
	 * Prepare CURL connection.
	 *
	 * @param   String   $url    URL to be used in connection.
	 * @param   String   $handle File handle to be used in connection.
	 */
	protected function prepareConnection($url = null, $handle = null){

		// Connection needs to be created
		if( !is_resource($this->connection) ) {

			// Initialise connection
			$this->connection = curl_init();

			// Configure CURL
			curl_setopt($this->connection, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->connection, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($this->connection, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
		}

		// Set URL
		if( !is_null($url) ) {
			curl_setopt($this->connection, CURLOPT_URL, $url);
		}

		// Set File Handle
		if( !is_null($handle) ) {
			curl_setopt($this->connection, CURLOPT_TIMEOUT, 100);
			curl_setopt($this->connection, CURLOPT_FILE, $handle);
			curl_setopt($this->connection, CURLOPT_FOLLOWLOCATION, true);
		}
	}

	/**
	 * Download a file to local filesystem.
	 *
	 * @param type $url
	 * @param type $path
	 *
	 * @throws Exception
	 */
	protected function downloadFile($url, $path) {

		if( function_exists('curl_init') ) {
			// Create file handle
			$handle = fopen ($path, 'w+');

			// Prepare CURL connection
			$this->prepareConnection($url, $handle);

			// Run CURL
			curl_exec($this->connection);
			$error = curl_error($this->connection);
			if( !empty($error) ) {
				throw new Exception('(Curl) '.$error, 502);
			}

			// Close file handle
			fclose($handle);

			// Close CURL connection
			curl_close($this->connection);

		} else {

			$options = $file_get_contents_options = array(
				'ssl' => array(
					"verify_peer" => false,
					"verify_peer_name" => false
				),
				'http' => array(
					'user_agent' => $_SERVER['HTTP_USER_AGENT']
				)
			);

			file_put_contents(
				$path,
				file_get_contents(
					$url, false,
					stream_context_create($options)
				)
			);
		}
	}

	/**
	 * Store cache
	 */
	public function __destruct()
	{
		if (!empty($this->cache['versions'])) {
			file_put_contents(__DIR__.'/getjoomla.cache', json_encode($this->cache));
		}
	}

	/**
	 * Get list of available Joomla! Releases
	 *
	 * @return Array
	 */
	public function getVersions()
	{

		if (empty($this->cache['versions'])) {
			$buffer  = $this->getURLContents('https://api.github.com/repos/joomla/joomla-cms/releases');
			$list	 = json_decode($buffer);
			
			// Search for full installation asset
			foreach ($list AS $version) {
				foreach( $version->assets AS $asset ) {
					if( stripos($asset->name, 'Full_Package.zip') ) {
						$url = $asset->browser_download_url;
					}
				}
				$this->cache['versions'][$version->name] = $url;
			}
		}

		return $this->cache['versions'];
	}

	/**
	 * Get informations about latest Joomla! release
	 *
	 * @return   String
	 */
	public function getLatestVersion()
	{
		if (empty($this->cache['latest'])) {
			$buffer					 = $this->getURLContents('https://api.github.com/repos/joomla/joomla-cms/releases/latest');
			$tmp					 = json_decode($buffer);
			$this->cache['latest']	 = $tmp->name;
		}

		return $this->cache['latest'];
	}

	/**
	 * Download package, unpack it, install and redirect to
	 * Joomla! installation page.
	 *
	 * @global   String   $baseURL   Base URL for script.
	 * @param    String   $url_zip   URL to Joomla! installation package.
	 *
	 * @throws Exception
	 */
	public function prepare($url_zip){
		global $baseURL;

		// Download zip
		$this->downloadFile($url_zip, __DIR__.'/joomla.zip');

		// Remove this script
		unlink(__FILE__);
		unlink(__DIR__.'/getjoomla.cache');
		
		// Unpack
		$package = new ZipArchive;
		if ($package->open(__DIR__.'/joomla.zip') === TRUE) {
			$package->extractTo(__DIR__);
			$package->close();

			// Remove package
			unlink(__DIR__.'/joomla.zip');
			$this->cache = null;
		} else {
			throw new Exception('Cannot extract joomla.zip', 502);
		}

		// Clone htaccess
		copy(__DIR__.'/htaccess.txt', __DIR__.'/.htaccess');


		// Redierct to installation
		header('Location: '.$baseURL.'installation/index.php');
	}

	/**
	 * Check Class requirements
	 *
	 * @throws Exception
	 */
	public function checkRequirements(){
		
		// Check if PHP can get remote contect
		if( !ini_get('allow_url_fopen') OR !function_exists('curl_init') ) {
			throw new Exception('This class require <b>CURL</b> or <b>allow_url_fopen</b> have to be enabled in PHP configuration.', 502);
		}

		// Check if server allow to extract zip files
		if( !class_exists('ZipArchive')) {
			throw new Exception('Class <b>ZipArchive</b> is not available in current PHP configuration.', 502);
		}

	}
}

// Create new Installer instance
$installer = new Installer;

// Run tasks
try {

	// First check if server meets requirements
	$installer->checkRequirements();

	// Get versions data
	$versions	 = $installer->getVersions();
	$latest		 = $installer->getLatestVersion();

	// If this is install task
	if (isset($_GET['install'])) {
		$installer->prepare($_GET['install']);
	}
	
} catch (Exception $e) {
	die('ERROR: '.$e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
		<base href="<?php echo $baseURL ?>" />
        <meta name="description" content="">
        <meta name="author" content="">
        <title>GetJoomla</title>

        <!-- Bootstrap core CSS -->
        <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet">

		<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
		<script type="text/javascript" src="//maxcdn.bootstrapcdn.com/bootstrap/3.3.1/js/bootstrap.min.js"></script>

        <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
        <!--[if lt IE 9]>
          <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
          <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
        <![endif]-->

		<style>
			.container {padding-top:30px;padding-bottom:30px;}
			.windows8{position:relative;width:56px;height:56px;margin:auto;top:45%}.windows8 .wBall{position:absolute;width:53px;height:53px;opacity:0;transform:rotate(225deg);-o-transform:rotate(225deg);-ms-transform:rotate(225deg);-webkit-transform:rotate(225deg);-moz-transform:rotate(225deg);animation:orbit 5.4425s infinite;-o-animation:orbit 5.4425s infinite;-ms-animation:orbit 5.4425s infinite;-webkit-animation:orbit 5.4425s infinite;-moz-animation:orbit 5.4425s infinite}.windows8 .wBall .wInnerBall{position:absolute;width:7px;height:7px;background:#fff;left:0;top:0;border-radius:7px}.windows8 #wBall_1{animation-delay:1.186s;-o-animation-delay:1.186s;-ms-animation-delay:1.186s;-webkit-animation-delay:1.186s;-moz-animation-delay:1.186s}.windows8 #wBall_2{animation-delay:.233s;-o-animation-delay:.233s;-ms-animation-delay:.233s;-webkit-animation-delay:.233s;-moz-animation-delay:.233s}.windows8 #wBall_3{animation-delay:.4765s;-o-animation-delay:.4765s;-ms-animation-delay:.4765s;-webkit-animation-delay:.4765s;-moz-animation-delay:.4765s}.windows8 #wBall_4{animation-delay:.7095s;-o-animation-delay:.7095s;-ms-animation-delay:.7095s;-webkit-animation-delay:.7095s;-moz-animation-delay:.7095s}.windows8 #wBall_5{animation-delay:.953s;-o-animation-delay:.953s;-ms-animation-delay:.953s;-webkit-animation-delay:.953s;-moz-animation-delay:.953s}@keyframes orbit{0%{opacity:1;z-index:99;transform:rotate(180deg);animation-timing-function:ease-out}7%{opacity:1;transform:rotate(300deg);animation-timing-function:linear;origin:0}30%{opacity:1;transform:rotate(410deg);animation-timing-function:ease-in-out;origin:7%}39%{opacity:1;transform:rotate(645deg);animation-timing-function:linear;origin:30%}70%{opacity:1;transform:rotate(770deg);animation-timing-function:ease-out;origin:39%}75%{opacity:1;transform:rotate(900deg);animation-timing-function:ease-out;origin:70%}76%{opacity:0;transform:rotate(900deg)}100%{opacity:0;transform:rotate(900deg)}}@-o-keyframes orbit{0%{opacity:1;z-index:99;-o-transform:rotate(180deg);-o-animation-timing-function:ease-out}7%{opacity:1;-o-transform:rotate(300deg);-o-animation-timing-function:linear;-o-origin:0}30%{opacity:1;-o-transform:rotate(410deg);-o-animation-timing-function:ease-in-out;-o-origin:7%}39%{opacity:1;-o-transform:rotate(645deg);-o-animation-timing-function:linear;-o-origin:30%}70%{opacity:1;-o-transform:rotate(770deg);-o-animation-timing-function:ease-out;-o-origin:39%}75%{opacity:1;-o-transform:rotate(900deg);-o-animation-timing-function:ease-out;-o-origin:70%}76%{opacity:0;-o-transform:rotate(900deg)}100%{opacity:0;-o-transform:rotate(900deg)}}@-ms-keyframes orbit{0%{opacity:1;z-index:99;-ms-transform:rotate(180deg);-ms-animation-timing-function:ease-out}7%{opacity:1;-ms-transform:rotate(300deg);-ms-animation-timing-function:linear;-ms-origin:0}30%{opacity:1;-ms-transform:rotate(410deg);-ms-animation-timing-function:ease-in-out;-ms-origin:7%}39%{opacity:1;-ms-transform:rotate(645deg);-ms-animation-timing-function:linear;-ms-origin:30%}70%{opacity:1;-ms-transform:rotate(770deg);-ms-animation-timing-function:ease-out;-ms-origin:39%}75%{opacity:1;-ms-transform:rotate(900deg);-ms-animation-timing-function:ease-out;-ms-origin:70%}76%{opacity:0;-ms-transform:rotate(900deg)}100%{opacity:0;-ms-transform:rotate(900deg)}}@-webkit-keyframes orbit{0%{opacity:1;z-index:99;-webkit-transform:rotate(180deg);-webkit-animation-timing-function:ease-out}7%{opacity:1;-webkit-transform:rotate(300deg);-webkit-animation-timing-function:linear;-webkit-origin:0}30%{opacity:1;-webkit-transform:rotate(410deg);-webkit-animation-timing-function:ease-in-out;-webkit-origin:7%}39%{opacity:1;-webkit-transform:rotate(645deg);-webkit-animation-timing-function:linear;-webkit-origin:30%}70%{opacity:1;-webkit-transform:rotate(770deg);-webkit-animation-timing-function:ease-out;-webkit-origin:39%}75%{opacity:1;-webkit-transform:rotate(900deg);-webkit-animation-timing-function:ease-out;-webkit-origin:70%}76%{opacity:0;-webkit-transform:rotate(900deg)}100%{opacity:0;-webkit-transform:rotate(900deg)}}@-moz-keyframes orbit{0%{opacity:1;z-index:99;-moz-transform:rotate(180deg);-moz-animation-timing-function:ease-out}7%{opacity:1;-moz-transform:rotate(300deg);-moz-animation-timing-function:linear;-moz-origin:0}30%{opacity:1;-moz-transform:rotate(410deg);-moz-animation-timing-function:ease-in-out;-moz-origin:7%}39%{opacity:1;-moz-transform:rotate(645deg);-moz-animation-timing-function:linear;-moz-origin:30%}70%{opacity:1;-moz-transform:rotate(770deg);-moz-animation-timing-function:ease-out;-moz-origin:39%}75%{opacity:1;-moz-transform:rotate(900deg);-moz-animation-timing-function:ease-out;-moz-origin:70%}76%{opacity:0;-moz-transform:rotate(900deg)}100%{opacity:0;-moz-transform:rotate(900deg)}}
			.loader {
				background:#21417A;position:absolute;top:0;left:0;width:0;height:0;overflow:hidden;opacity:0;transition:opacity 0.5s linear;
				z-index:9999;
			}
			.loader.enabled {
				width:100%;height:100%;opacity:1
			}
		</style>
		<script>
			$(document).ready(function(){
				$('form').submit(function(){
					$('.loader').addClass('enabled');
				});
			});
		</script>
    </head>

    <body>

        <div class="container">
            <div class="jumbotron text-center">
                <h1>getJoomla <small>v1.0.0</small></h1>
                <p class="lead">Just a crazy fast script to download and prepare Joomla! installation.</p>
				<form action="<?php echo $baseURL.basename(__FILE__) ?>" method="get">
					<div class="input-group">
						<select class="form-control" name="install">
							<optgroup label="Latest version">
								<?php
								foreach ($versions AS $version => $url):
									if ($version === $latest):
										?>
										<option value="<?php echo $url ?>" <?php
											echo ($version === $latest ? 'selected="true" style="font-weight:700"'
													: '')
										?>>
										<?php echo $version ?>
										</option>
										<?php
									endif;
								endforeach
								?>
							</optgroup>
							<optgroup label="Other versions">
									<?php
									foreach ($versions AS $version => $url):
										if ($version !== $latest):
											?>
										<option value="<?php echo $url ?>" <?php echo ($version === $latest ? 'selected'
										: '') ?>>
										<?php echo $version ?>
										</option>
											<?php
										endif;
									endforeach
									?>
							</optgroup>
						</select>
						<span class="input-group-btn">
							<input type="submit" class="btn btn-primary" value="Install"/>
						</span>
					</div><!-- /input-group -->
				</form>
            </div>

            <div class="row marketing">
                <div class="col-lg-6">
                    <h4>How it works</h4>
                    <p>This script downloads selected release from <a href="https://github.com/joomla/joomla-cms/releases">Joomla! Github repository</a>, unpacks it, creates <code>.htaccess</code>, removes itself and then redirects you to Joomla! installation. In short: just select version and click install to run Joomla! installer.</p>
                </div>

                <div class="col-lg-6">
                    <h4>Warning</h4>
                    <p>This script is free for everyone. Im not responsible for any damage it can make. Remember to always have a copy of files you have on this server.</p>

                    <h4>License</h4>
                    <p>This script is released under <a href="http://www.gnu.org/licenses/gpl-3.0.txt">GNU/GPL 3.0 license</a>. Free for commercial and non-commercial usage.</p>
                </div>
            </div>

            <footer class="footer">
                <p>&copy; 2016 BestProject</p>
            </footer>

        </div>
		<div class="loader">
			<div class="windows8">
				<div class="wBall" id="wBall_1">
					<div class="wInnerBall"></div>
				</div>
				<div class="wBall" id="wBall_2">
					<div class="wInnerBall"></div>
				</div>
				<div class="wBall" id="wBall_3">
					<div class="wInnerBall"></div>
				</div>
				<div class="wBall" id="wBall_4">
					<div class="wInnerBall"></div>
				</div>
				<div class="wBall" id="wBall_5">
					<div class="wInnerBall"></div>
				</div>
			</div>
		</div>
    </body>
</html>
