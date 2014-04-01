<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2014 Leo Feyer
 *
 * @package Core
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * Store the microtime
 */
define('TL_START', microtime(true));


/**
 * Define the root path to the Contao installation
 */
define('TL_ROOT', dirname(__DIR__));


/**
 * Define the login status constants in the back end (see #4099, #5279)
 */
if (TL_MODE == 'BE')
{
	define('BE_USER_LOGGED_IN', false);
	define('FE_USER_LOGGED_IN', false);
}

define('TL_REFERER_ID', substr(md5(TL_START), 0, 8));


/**
 * Include the helpers
 */
require TL_ROOT . '/system/helper/functions.php';
require TL_ROOT . '/system/config/constants.php';
require TL_ROOT . '/system/helper/interface.php';
require TL_ROOT . '/system/helper/exception.php';


/**
 * Try to disable the PHPSESSID
 */
@ini_set('session.use_trans_sid', 0);
@ini_set('session.cookie_httponly', true);


/**
 * Set the error and exception handler
 */
@set_error_handler('__error');
@set_exception_handler('__exception');


/**
 * Log PHP errors
 */
@ini_set('error_log', TL_ROOT . '/system/logs/error.log');


/**
 * Register the helper autoloader
 */
function helper_loader($class) {
	$class = str_replace('Contao\\', '', $class);
	$path  = 'system/modules/core/library/Contao/' . $class . '.php';

	if (file_exists(TL_ROOT . '/' . $path)) {
		require TL_ROOT . '/' . $path;
		class_alias('Contao\\' . $class, $class);
	}
}

spl_autoload_register('helper_loader');


/**
 * Preload the configuration (see #5872)
 */
Config::preload();


/**
 * Try to load the modules
 */
try
{
	ClassLoader::scanAndRegister();
}
catch (UnresolvableDependenciesException $e)
{
	die($e->getMessage()); // see #6343
}


/**
 * Unregister the helper autoloader
 */
spl_autoload_unregister('helper_loader');


/**
 * Include the Composer autoloader
 */
require_once TL_ROOT . '/vendor/autoload.php';


/**
 * Override some SwiftMailer defaults
 */
Swift::init(function()
{
	$preferences = Swift_Preferences::getInstance();

	if (!Config::get('useFTP'))
	{
		$preferences->setTempDir(TL_ROOT . '/system/tmp')->setCacheType('disk');
	}

	$preferences->setCharset(Config::get('characterSet'));
});


/**
 * Define the relative path to the installation (see #5339)
 */
if (file_exists(TL_ROOT . '/system/config/pathconfig.php'))
{
	define('TL_PATH', include TL_ROOT . '/system/config/pathconfig.php');
}
elseif (TL_MODE == 'BE')
{
	define('TL_PATH', preg_replace('/\/contao\/[^\/]*$/i', '', Environment::get('requestUri')));
}
else
{
	define('TL_PATH', null); // cannot be reliably determined
}


/**
 * Start the session
 */
@session_set_cookie_params(0, (TL_PATH ?: '/')); // see #5339
@session_start();


/**
 * Get the Config instance
 */
$objConfig = Config::getInstance();


/**
 * Set the website path (backwards compatibility)
 */
Config::set('websitePath', TL_PATH);


/**
 * Initialize the Input and RequestToken class
 */
Input::initialize();
RequestToken::initialize();


/**
 * Set the default language
 */
if (!isset($_SESSION['TL_LANGUAGE']))
{
	// Check the user languages
	$langs = Environment::get('httpAcceptLanguage');
	array_push($langs, 'en'); // see #6533

	foreach ($langs as $lang)
	{
		if (is_dir(TL_ROOT . '/system/modules/core/languages/' . str_replace('-', '_', $lang)))
		{
			$_SESSION['TL_LANGUAGE'] = $lang;
			break;
		}
	}

	unset($langs, $lang);
}

$GLOBALS['TL_LANGUAGE'] = $_SESSION['TL_LANGUAGE'];


/**
 * Show the "incomplete installation" message
 */
if (!$objConfig->isComplete() && Environment::get('script') != 'contao/install.php')
{
	die_nicely('be_incomplete', 'The installation has not been completed. Open the Contao install tool to continue.');
}


/**
 * Set error_reporting (see #5001)
 */
if (Input::cookie('TL_INSTALL_AUTH') && !empty($_SESSION['TL_INSTALL_AUTH']) && Input::cookie('TL_INSTALL_AUTH') == $_SESSION['TL_INSTALL_AUTH'] && $_SESSION['TL_INSTALL_EXPIRE'] > time())
{
	@ini_set('display_errors', 1);
	error_reporting(E_ALL|E_STRICT);
}
else
{
	@ini_set('display_errors', (Config::get('displayErrors') ? 1 : 0));
	error_reporting((Config::get('displayErrors') || Config::get('logErrors')) ? E_ALL|E_STRICT : 0);
}


/**
 * Set the timezone
 */
@ini_set('date.timezone', Config::get('timeZone'));
@date_default_timezone_set(Config::get('timeZone'));


/**
 * Store the relative path
 *
 * Only store this value if the temp directory is writable and the local
 * configuration file exists, otherwise it will initialize a Files object and
 * prevent the install tool from loading the Safe Mode Hack (see #3215).
 */
if (TL_PATH !== null && !file_exists(TL_ROOT . '/system/config/pathconfig.php'))
{
	if (is_writable(TL_ROOT . '/system/tmp') && file_exists(TL_ROOT . '/system/config/localconfig.php'))
	{
		try
		{
			$objFile = new File('system/config/pathconfig.php', true);
			$objFile->write("<?php\n\n// Relative path to the installation\nreturn '" . TL_PATH . "';\n");
			$objFile->close();
		}
		catch (Exception $e)
		{
			log_message($e->getMessage());
		}
	}
}


/**
 * Set the mbstring encoding
 */
if (USE_MBSTRING && function_exists('mb_regex_encoding'))
{
	mb_regex_encoding(Config::get('characterSet'));
}


/**
 * HOOK: add custom logic (see #5665)
 */
if (isset($GLOBALS['TL_HOOKS']['initializeSystem']) && is_array($GLOBALS['TL_HOOKS']['initializeSystem']))
{
	foreach ($GLOBALS['TL_HOOKS']['initializeSystem'] as $callback)
	{
		System::importStatic($callback[0])->$callback[1]();
	}
}


/**
 * Include the custom initialization file
 */
if (file_exists(TL_ROOT . '/system/config/initconfig.php'))
{
	include TL_ROOT . '/system/config/initconfig.php';
}


/**
 * Check the request token upon POST requests
 */
if ($_POST && !RequestToken::validate(Input::post('REQUEST_TOKEN')))
{
	// Force a JavaScript redirect upon Ajax requests (IE requires absolute link)
	if (Environment::get('isAjaxRequest'))
	{
		header('HTTP/1.1 204 No Content');
		header('X-Ajax-Location: ' . Environment::get('base') . 'contao/');
	}
	else
	{
		header('HTTP/1.1 400 Bad Request');
		die_nicely('be_referer', 'Invalid request token. Please <a href="javascript:window.location.href=window.location.href">go back</a> and try again.');
	}

	exit;
}
