<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );
// Only Runner::init_ee() defines it, and the context never runs that.
if ( ! defined( 'EE_PROXY_TYPE' ) ) {
	define( 'EE_PROXY_TYPE', 'services_global-nginx-proxy_1' );
}

class FeatureContext implements Context
{
	public $command;
	public $commands = [];
	public $webroot_path;
	public $ee_path;

	/**
	 * Every site this suite creates starts with this prefix. Cleanup only ever touches these sites.
	 */
	const SITE_PREFIX = 'bh-auth-command-';

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Anchored (the upstream pattern matched any step containing a double-quoted string).
	 * The command cannot contain the quote character that wraps it.
	 *
	 * @When /^I run (?|'([^']*)'|"([^"]*)")$/
	 */
	public function iRun($command)
	{
		$this->commands[] = EE::launch($this->replace_variables($command), false, true);
	}

	/**
	 * @When I create subsite :subsite in :site
	 */
	public function iCreateSubsiteInOfType($subsite, $site )
	{
		$php_container = implode( explode( '.', $subsite ) ) . '_php_1';

		EE::launch( "docker exec -it --user='www-data' $php_container sh -c 'wp site create --slug=$site'", false, true );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /(STDOUT|STDERR) should return exactly/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) of command :index should return exactly/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if ( $expected_out !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) should return something like/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if (strpos($command_output, $expected_out) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /(STDOUT|STDERR) of command :index should return something like/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if (strpos($command_output, $expected_out) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->commands = [];
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		if((int) $expected_running_containers !== $running_containers) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * Deletes only this suite's sites (SITE_PREFIX) and whatever auth state they left behind.
	 * The upstream hook deleted every site on the machine.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		self::cleanup_prefixed_sites();
		self::cleanup_leftover_global_test_auth();

		if(file_exists('ee.phar')) {
			unlink('ee.phar');
		}
		if(file_exists('ee-old.phar')) {
			unlink('ee-old.phar');
		}
	}

	/*
	 * =========================================================================
	 * Shared steps and helpers (added for auth-command).
	 *
	 * Generic enough to move to a shared context later. All of them act on the
	 * LAST command run in the scenario (the upstream steps above check the first
	 * one). Strings in commands, paths, PyStrings and tables may use {NAME}
	 * placeholders: PUBLIC_IP, EE_ROOT_DIR, EE_BIN, FRONTEND_SUBNET_IP and any
	 * value saved with one of the "I save ..." steps.
	 * =========================================================================
	 */

	/** Seconds an HTTP assertion keeps retrying (nginx reloads are asynchronous). */
	const HTTP_SETTLE_SECONDS = 10;

	/** @var array Scenario variables for {NAME} placeholders. */
	private $variables = [];

	/** @var string[] Tags of the running scenario, feature tags included. */
	private $scenario_tags = [];

	/** @var array|null Global auth state captured before a @destructive scenario. */
	private $global_auth_snapshot = null;

	/**
	 * @BeforeScenario
	 */
	public function rememberScenarioState(BeforeScenarioScope $scope)
	{
		$this->scenario_tags = array_merge( $scope->getFeature()->getTags(), $scope->getScenario()->getTags() );
		if ( in_array( 'destructive', $this->scenario_tags, true ) ) {
			$this->global_auth_snapshot = self::global_auth_state();
		}
	}

	/**
	 * Resets auth on this suite's sites, then restores the global auth state for @destructive scenarios.
	 *
	 * @AfterScenario
	 */
	public function resetAuthState(AfterScenarioScope $scope)
	{
		foreach ( self::prefixed_sites_with_auth_rows() as $site ) {
			self::reset_site_auth( $site );
		}
		if ( null !== $this->global_auth_snapshot ) {
			self::restore_global_auth_state( $this->global_auth_snapshot );
			$this->global_auth_snapshot = null;
		}
	}

	/* ----------------------------- Commands ------------------------------ */

	/**
	 * @When I run :command in the directory :dir
	 */
	public function iRunInTheDirectory($command, $dir)
	{
		$dir = $this->replace_variables( $dir );
		if ( ! is_dir( $dir ) ) {
			throw new Exception( "Directory $dir does not exist." );
		}
		$this->commands[] = EE::launch( $this->replace_variables( $command ), false, true, [], null, $dir );
	}

	/**
	 * @Then the command should succeed
	 */
	public function theCommandShouldSucceed()
	{
		$result = $this->last_command();
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Expected exit code 0.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the command should fail
	 */
	public function theCommandShouldFail()
	{
		$result = $this->last_command();
		if ( 0 === $result->return_code ) {
			throw new Exception( "Expected a non-zero exit code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the exit code should be :code
	 */
	public function theExitCodeShouldBe($code)
	{
		$result = $this->last_command();
		if ( (int) $code !== $result->return_code ) {
			throw new Exception( "Expected exit code $code.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain:$/
	 */
	public function outputShouldContain($stream, PyStringNode $expected)
	{
		$expected = $this->replace_variables( $expected->getRaw() );
		if ( false === strpos( $this->output( $stream ), $expected ) ) {
			throw new Exception( "$stream does not contain:\n$expected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should not contain:$/
	 */
	public function outputShouldNotContain($stream, PyStringNode $unexpected)
	{
		$unexpected = $this->replace_variables( $unexpected->getRaw() );
		if ( false !== strpos( $this->output( $stream ), $unexpected ) ) {
			throw new Exception( "$stream unexpectedly contains:\n$unexpected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function outputShouldBeEmpty($stream)
	{
		if ( '' !== trim( $this->output( $stream ) ) ) {
			throw new Exception( "$stream is not empty.\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be exactly:$/
	 */
	public function outputShouldBeExactly($stream, PyStringNode $expected)
	{
		$expected = $this->replace_variables( $expected->getRaw() );
		if ( trim( $this->output( $stream ) ) !== trim( $expected ) ) {
			throw new Exception( "$stream is not exactly:\n$expected\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * The PyString is a full PCRE pattern including delimiters and modifiers.
	 *
	 * @Then /^(STDOUT|STDERR) should match:$/
	 */
	public function outputShouldMatch($stream, PyStringNode $pattern)
	{
		$pattern = $this->replace_variables( trim( $pattern->getRaw() ) );
		$matched = @preg_match( $pattern, $this->output( $stream ) );
		if ( false === $matched ) {
			throw new Exception( "Invalid regular expression: $pattern" );
		}
		if ( 1 !== $matched ) {
			throw new Exception( "$stream does not match $pattern\n" . self::describe( $this->last_command() ) );
		}
	}

	/**
	 * @Then STDOUT should be valid JSON
	 */
	public function stdoutShouldBeValidJson()
	{
		$this->json_output();
	}

	/**
	 * @Then the JSON output should have :count items
	 */
	public function theJsonOutputShouldHaveItems($count)
	{
		$items = $this->json_output();
		if ( count( $items ) !== (int) $count ) {
			throw new Exception( sprintf( "Expected %d JSON items, got %d.\n%s", $count, count( $items ), self::describe( $this->last_command() ) ) );
		}
	}

	/**
	 * Every table row must match at least one JSON item on the given columns.
	 *
	 * @Then the JSON output should contain items:
	 */
	public function theJsonOutputShouldContainItems(TableNode $table)
	{
		$items = $this->json_output();
		foreach ( $this->table_hash( $table ) as $expected ) {
			if ( null === self::find_matching_item( $items, $expected ) ) {
				throw new Exception( 'No JSON item matches ' . json_encode( $expected ) . "\n" . self::describe( $this->last_command() ) );
			}
		}
	}

	/**
	 * @Then the JSON output should not contain items:
	 */
	public function theJsonOutputShouldNotContainItems(TableNode $table)
	{
		$items = $this->json_output();
		foreach ( $this->table_hash( $table ) as $unexpected ) {
			if ( null !== self::find_matching_item( $items, $unexpected ) ) {
				throw new Exception( 'A JSON item unexpectedly matches ' . json_encode( $unexpected ) . "\n" . self::describe( $this->last_command() ) );
			}
		}
	}

	/**
	 * @When I save the JSON item count as :var
	 */
	public function iSaveTheJsonItemCountAs($var)
	{
		$this->set_variable( $var, (string) count( $this->json_output() ) );
	}

	/**
	 * @When I save the :field of the JSON item where :key is :value as :var
	 */
	public function iSaveTheFieldOfTheJsonItemWhereIsAs($field, $key, $value, $var)
	{
		$item = self::find_matching_item( $this->json_output(), [ $key => $this->replace_variables( $value ) ] );
		if ( null === $item || ! array_key_exists( $field, $item ) ) {
			throw new Exception( "No JSON item with $key = $value and a '$field' field.\n" . self::describe( $this->last_command() ) );
		}
		$this->set_variable( $var, (string) $item[ $field ] );
	}

	/**
	 * Saves the value of a "Label: value" line of STDOUT.
	 *
	 * @When I save the :label value from STDOUT as :var
	 */
	public function iSaveTheValueFromStdoutAs($label, $var)
	{
		if ( ! preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':[ \t]*(.*)$/m', $this->output( 'STDOUT' ), $m ) ) {
			throw new Exception( "No '$label:' line in STDOUT.\n" . self::describe( $this->last_command() ) );
		}
		$this->set_variable( $var, trim( $m[1] ) );
	}

	/* ------------------------------- Sites ------------------------------- */

	/**
	 * Creates the site if it is missing (and enables it if disabled), so scenarios can share it.
	 *
	 * @Given the :type site :site exists
	 */
	public function theSiteOfTypeExists($type, $site)
	{
		$this->theSiteOfTypeExistsWithFlags( $type, $site, '' );
	}

	/**
	 * Flags are only used when the site has to be created.
	 *
	 * @Given the :type site :site exists with flags :flags
	 */
	public function theSiteOfTypeExistsWithFlags($type, $site, $flags)
	{
		self::assert_prefixed_site( $site );
		$row = self::site_row( $site );
		if ( $row ) {
			if ( $row['site_type'] !== $type ) {
				throw new Exception( "Site $site exists with type {$row['site_type']}, expected $type." );
			}
			if ( ! (int) $row['site_enabled'] ) {
				self::ee_or_fail( 'site enable ' . escapeshellarg( $site ) );
			}

			return;
		}
		self::ee_or_fail( sprintf( 'site create %s --type=%s %s', escapeshellarg( $site ), escapeshellarg( $type ), $this->replace_variables( $flags ) ) );
	}

	/**
	 * @Given the site :site does not exist
	 */
	public function theSiteDoesNotExist($site)
	{
		self::assert_prefixed_site( $site );
		if ( self::site_row( $site ) ) {
			self::ee_or_fail( 'site delete ' . escapeshellarg( $site ) . ' --yes' );
		}
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist($site)
	{
		if ( ! self::site_row( $site ) ) {
			throw new Exception( "Site $site has no row in the sites table." );
		}
	}

	/**
	 * @Then the site :site should not exist
	 */
	public function theSiteShouldNotExist($site)
	{
		if ( self::site_row( $site ) ) {
			throw new Exception( "Site $site still has a row in the sites table." );
		}
	}

	/* ----------------------------- Auth state ---------------------------- */

	/**
	 * @Given the site :site has no http auth or whitelist
	 */
	public function theSiteHasNoHttpAuthOrWhitelist($site)
	{
		self::assert_prefixed_site( $site );
		self::reset_site_auth( $site );
	}

	/**
	 * Deletes every global (`default`) auth user through `ee auth delete global`.
	 *
	 * @Given there is no global http auth
	 */
	public function thereIsNoGlobalHttpAuth()
	{
		$this->require_destructive( __FUNCTION__ );
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default'" ) ) {
			self::ee_or_fail( 'auth delete global' );
		}
		$left = self::db_rows( "SELECT username FROM auth_users WHERE site_url = 'default'" );
		if ( $left ) {
			throw new Exception( 'Global auth users are left after `ee auth delete global`: ' . json_encode( $left ) );
		}
	}

	/**
	 * Removes the admin-tools auth row and file directly (ee has no command for it). Restored after the scenario.
	 *
	 * @Given there is no admin-tools http auth
	 */
	public function thereIsNoAdminToolsHttpAuth()
	{
		$this->require_destructive( __FUNCTION__ );
		self::db()->exec( "DELETE FROM auth_users WHERE site_url = 'default_admin_tools'" );
		self::write_htpasswd( 'default_admin_tools', [] );
		self::reload_proxy();
	}

	/**
	 * Keeps an existing admin-tools auth, otherwise creates one (user `easyengine`, like init_global_admin_tools_auth()).
	 *
	 * @Given the admin-tools http auth exists
	 */
	public function theAdminToolsHttpAuthExists()
	{
		$this->require_destructive( __FUNCTION__ );
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default_admin_tools'" ) ) {
			return;
		}
		if ( self::db_rows( "SELECT id FROM auth_users WHERE site_url = 'default'" ) ) {
			throw new Exception( 'Global auth exists, so ee would not create an admin-tools auth. Use "there is no global http auth" first.' );
		}
		$row = [ 'site_url' => 'default_admin_tools', 'username' => 'easyengine', 'password' => 'bh-admin-' . bin2hex( random_bytes( 6 ) ) ];
		self::insert_row( 'auth_users', $row );
		self::write_htpasswd( 'default_admin_tools', [ $row ] );
		self::reload_proxy();
	}

	/**
	 * @Given no site has admin-tools or mailhog enabled
	 */
	public function noSiteHasAdminToolsOrMailhogEnabled()
	{
		$sites = self::db_rows( 'SELECT site_url FROM sites WHERE admin_tools = 1 OR mailhog_enabled = 1' );
		if ( $sites ) {
			throw new Exception( 'These sites have admin-tools or mailhog enabled: ' . implode( ', ', array_column( $sites, 'site_url' ) ) );
		}
	}

	/**
	 * @Given the frontend subnet is globally whitelisted
	 */
	public function theFrontendSubnetIsGloballyWhitelisted()
	{
		$subnet = self::frontend_subnet_ip();
		if ( ! self::db_rows( "SELECT id FROM auth_ips WHERE site_url = 'default' AND ip = ?", [ $subnet ] ) ) {
			throw new Exception( "The frontend subnet $subnet is not in the global whitelist; requests to 127.0.0.1 would not bypass auth." );
		}
	}

	/* ------------------------------ Database ----------------------------- */

	/**
	 * Vertical table: | column | value |.
	 *
	 * @Then the :table table should contain a row with:
	 */
	public function theTableShouldContainARowWith($table, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		if ( 0 === self::count_rows( $table, $conditions ) ) {
			throw new Exception( "No row in $table with " . json_encode( $conditions ) . "\n" . self::dump_table( $table ) );
		}
	}

	/**
	 * @Then the :table table should not contain a row with:
	 */
	public function theTableShouldNotContainARowWith($table, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		if ( 0 !== self::count_rows( $table, $conditions ) ) {
			throw new Exception( "Unexpected row in $table with " . json_encode( $conditions ) . "\n" . self::dump_table( $table ) );
		}
	}

	/**
	 * @Then the :table table should have :count rows with:
	 */
	public function theTableShouldHaveRowsWith($table, $count, TableNode $where)
	{
		$conditions = $this->rows_hash( $where );
		$actual     = self::count_rows( $table, $conditions );
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected $count rows in $table with " . json_encode( $conditions ) . ", found $actual.\n" . self::dump_table( $table ) );
		}
	}

	/* ------------------------------- Files ------------------------------- */

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist($path)
	{
		$path = $this->replace_variables( $path );
		if ( ! file_exists( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist($path)
	{
		$path = $this->replace_variables( $path );
		if ( file_exists( $path ) ) {
			throw new Exception( "File $path exists. Content:\n" . @file_get_contents( $path ) );
		}
	}

	/**
	 * @Then the file :path should contain:
	 */
	public function theFileShouldContain($path, PyStringNode $expected)
	{
		$path     = $this->replace_variables( $path );
		$expected = $this->replace_variables( $expected->getRaw() );
		$content  = self::read_file( $path );
		if ( false === strpos( $content, $expected ) ) {
			throw new Exception( "File $path does not contain:\n$expected\nContent:\n$content" );
		}
	}

	/**
	 * A missing file passes (it contains nothing).
	 *
	 * @Then the file :path should not contain:
	 */
	public function theFileShouldNotContain($path, PyStringNode $unexpected)
	{
		$path       = $this->replace_variables( $path );
		$unexpected = $this->replace_variables( $unexpected->getRaw() );
		if ( ! file_exists( $path ) ) {
			return;
		}
		$content = self::read_file( $path );
		if ( false !== strpos( $content, $unexpected ) ) {
			throw new Exception( "File $path unexpectedly contains:\n$unexpected\nContent:\n$content" );
		}
	}

	/**
	 * @Then the file :path should contain the line :line exactly :count times
	 */
	public function theFileShouldContainTheLineExactlyTimes($path, $line, $count)
	{
		$path    = $this->replace_variables( $path );
		$line    = $this->replace_variables( $line );
		$content = self::read_file( $path );
		$lines   = array_map( 'trim', preg_split( '/\R/', $content ) );
		$actual  = count( array_keys( $lines, $line, true ) );
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected the line '$line' $count times in $path, found $actual. Content:\n$content" );
		}
	}

	/* ------------------------------ htpasswd ----------------------------- */

	/**
	 * :name is relative to the nginx-proxy htpasswd dir (a site name, `default` or `default_admin_tools`).
	 *
	 * @Then the htpasswd file :name should contain user :user
	 */
	public function theHtpasswdFileShouldContainUser($name, $user)
	{
		$user  = $this->replace_variables( $user );
		$users = self::htpasswd_users( $this->replace_variables( $name ) );
		if ( ! in_array( $user, $users, true ) ) {
			throw new Exception( "htpasswd/$name has no user '$user'. Users: " . implode( ', ', $users ) );
		}
	}

	/**
	 * @Then the htpasswd file :name should not contain user :user
	 */
	public function theHtpasswdFileShouldNotContainUser($name, $user)
	{
		$user = $this->replace_variables( $user );
		$path = self::htpasswd_path( $this->replace_variables( $name ) );
		if ( ! file_exists( $path ) ) {
			return;
		}
		$users = self::htpasswd_users( $this->replace_variables( $name ) );
		if ( in_array( $user, $users, true ) ) {
			throw new Exception( "htpasswd/$name unexpectedly has user '$user'." );
		}
	}

	/**
	 * @Then the htpasswd file :name should accept user :user with password :pass
	 */
	public function theHtpasswdFileShouldAcceptUserWithPassword($name, $user, $pass)
	{
		$result = $this->verify_htpasswd( $name, $user, $pass );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "htpasswd/$name rejects user '$user'.\n" . self::describe( $result ) );
		}
	}

	/**
	 * @Then the htpasswd file :name should reject user :user with password :pass
	 */
	public function theHtpasswdFileShouldRejectUserWithPassword($name, $user, $pass)
	{
		$result = $this->verify_htpasswd( $name, $user, $pass );
		if ( 0 === $result->return_code ) {
			throw new Exception( "htpasswd/$name accepts user '$user' with that password." );
		}
	}

	/* -------------------------------- HTTP ------------------------------- */

	/**
	 * @Then a GET request to :url with Host :host should return :code
	 */
	public function aGetRequestWithHostShouldReturn($url, $host, $code)
	{
		$this->assert_http_status( $url, $host, null, null, null, $code );
	}

	/**
	 * @Then a GET request to :url with Host :host as user :user with password :pass should return :code
	 */
	public function aGetRequestWithHostAsUserShouldReturn($url, $host, $user, $pass, $code)
	{
		$this->assert_http_status( $url, $host, null, $user, $pass, $code );
	}

	/**
	 * Resolves the URL's host to :ip (sets SNI and Host), certificate checks off.
	 *
	 * @Then a GET request to :url via :ip should return :code
	 */
	public function aGetRequestViaShouldReturn($url, $ip, $code)
	{
		$this->assert_http_status( $url, null, $ip, null, null, $code );
	}

	/**
	 * @Then a GET request to :url via :ip as user :user with password :pass should return :code
	 */
	public function aGetRequestViaAsUserShouldReturn($url, $ip, $user, $pass, $code)
	{
		$this->assert_http_status( $url, null, $ip, $user, $pass, $code );
	}

	/**
	 * @When I wait until a GET request to :url with Host :host returns :code up to :seconds seconds
	 */
	public function iWaitUntilAGetRequestReturns($url, $host, $code, $seconds)
	{
		$this->assert_http_status( $url, $host, null, null, null, $code, (int) $seconds );
	}

	/* ------------------------------- Docker ------------------------------ */

	/**
	 * @Then the docker container :name should be running
	 */
	public function theDockerContainerShouldBeRunning($name)
	{
		$result = EE::launch( "docker inspect -f '{{.State.Running}}' " . escapeshellarg( $this->replace_variables( $name ) ), false, true );
		if ( 'true' !== trim( $result->stdout ) ) {
			throw new Exception( "Container $name is not running.\n" . self::describe( $result ) );
		}
	}

	/* ------------------------------ Helpers ------------------------------ */

	private function last_command()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private static function describe($result)
	{
		return sprintf(
			"Command: %s\nExit code: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
			$result->command,
			$result->return_code,
			self::strip_colors( $result->stdout ),
			self::strip_colors( $result->stderr )
		);
	}

	private static function strip_colors($text)
	{
		return preg_replace( '/\e\[[0-9;]*m/', '', (string) $text );
	}

	private function output($stream)
	{
		$result = $this->last_command();

		return self::strip_colors( 'STDOUT' === $stream ? $result->stdout : $result->stderr );
	}

	private function json_output()
	{
		$data = json_decode( $this->output( 'STDOUT' ), true );
		if ( ! is_array( $data ) ) {
			throw new Exception( "STDOUT is not a JSON array/object.\n" . self::describe( $this->last_command() ) );
		}

		return $data;
	}

	private static function find_matching_item(array $items, array $expected)
	{
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $item ) || (string) $item[ $key ] !== (string) $value ) {
					continue 2;
				}
			}

			return $item;
		}

		return null;
	}

	private function set_variable($name, $value)
	{
		if ( ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $name ) ) {
			throw new Exception( "Variable names must be UPPER_SNAKE_CASE, got '$name'." );
		}
		$this->variables[ $name ] = $value;
	}

	private function replace_variables($text)
	{
		return preg_replace_callback(
			'/\{([A-Z][A-Z0-9_]*)\}/',
			function ( $m ) {
				$value = $this->variable( $m[1] );

				return null === $value ? $m[0] : $value;
			},
			(string) $text
		);
	}

	private function variable($name)
	{
		if ( array_key_exists( $name, $this->variables ) ) {
			return $this->variables[ $name ];
		}
		switch ( $name ) {
			case 'PUBLIC_IP':
				$value = self::public_ip();
				break;
			case 'EE_ROOT_DIR':
				$value = EE_ROOT_DIR;
				break;
			case 'EE_BIN':
				$value = self::ee_bin();
				break;
			case 'FRONTEND_SUBNET_IP':
				$value = self::frontend_subnet_ip();
				break;
			default:
				return null;
		}
		$this->variables[ $name ] = $value;

		return $value;
	}

	private function table_hash(TableNode $table)
	{
		$rows = [];
		foreach ( $table->getHash() as $row ) {
			$rows[] = array_map( [ $this, 'replace_variables' ], $row );
		}

		return $rows;
	}

	private function rows_hash(TableNode $table)
	{
		return array_map( [ $this, 'replace_variables' ], $table->getRowsHash() );
	}

	private function require_destructive($step)
	{
		if ( ! in_array( 'destructive', $this->scenario_tags, true ) ) {
			throw new Exception( "Step '$step' changes global state; tag the scenario or feature @destructive so it is restored afterwards." );
		}
	}

	private static function public_ip()
	{
		$ip = getenv( 'BEHAT_PUBLIC_IP' );
		if ( ! $ip ) {
			$ip = trim( (string) shell_exec( "hostname -I | awk '{print $1}'" ) );
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			throw new Exception( "Could not determine the public IP (got '$ip'); set BEHAT_PUBLIC_IP." );
		}

		return $ip;
	}

	private static function ee_bin()
	{
		return realpath( EE_ROOT ) . '/bin/ee';
	}

	private static function ee($args)
	{
		return EE::launch( escapeshellarg( self::ee_bin() ) . ' ' . $args, false, true );
	}

	private static function ee_or_fail($args)
	{
		$result = self::ee( $args );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Setup command failed.\n" . self::describe( $result ) );
		}

		return $result;
	}

	private static function assert_prefixed_site($site)
	{
		if ( 0 !== strpos( $site, self::SITE_PREFIX ) ) {
			throw new Exception( "Test sites must start with '" . self::SITE_PREFIX . "', got '$site'." );
		}
	}

	private static function db()
	{
		static $pdo = null;
		if ( null === $pdo ) {
			$pdo = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
			$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
			$pdo->setAttribute( PDO::ATTR_TIMEOUT, 30 );
		}

		return $pdo;
	}

	private static function db_rows($sql, array $params = [])
	{
		$statement = self::db()->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	private static function assert_identifier($name)
	{
		if ( ! preg_match( '/^[a-z_][a-z0-9_]*$/', $name ) ) {
			throw new Exception( "Invalid table or column name '$name'." );
		}
	}

	private static function count_rows($table, array $conditions)
	{
		self::assert_identifier( $table );
		$where  = [];
		$params = [];
		foreach ( $conditions as $column => $value ) {
			self::assert_identifier( $column );
			$where[]  = "$column = ?";
			$params[] = $value;
		}
		$sql = "SELECT COUNT(*) AS n FROM $table" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );

		return (int) self::db_rows( $sql, $params )[0]['n'];
	}

	private static function dump_table($table)
	{
		self::assert_identifier( $table );
		$rows = self::db_rows( "SELECT * FROM $table" );

		return "Rows in $table:\n" . implode( "\n", array_map( 'json_encode', $rows ) );
	}

	private static function insert_row($table, array $row)
	{
		self::assert_identifier( $table );
		array_map( [ __CLASS__, 'assert_identifier' ], array_keys( $row ) );
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ', ', array_keys( $row ) ), implode( ', ', array_fill( 0, count( $row ), '?' ) ) );
		self::db()->prepare( $sql )->execute( array_values( $row ) );
	}

	private static function site_row($site)
	{
		$rows = self::db_rows( 'SELECT site_url, site_type, site_enabled FROM sites WHERE site_url = ?', [ $site ] );

		return $rows ? $rows[0] : null;
	}

	private static function frontend_subnet_ip()
	{
		$rows = self::db_rows( "SELECT value FROM options WHERE \"key\" = 'frontend_subnet_ip'" );
		if ( ! $rows ) {
			throw new Exception( 'The frontend_subnet_ip option is not set.' );
		}

		return $rows[0]['value'];
	}

	private static function read_file($path)
	{
		if ( ! file_exists( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}

		return (string) file_get_contents( $path );
	}

	private static function htpasswd_path($name)
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $name;
	}

	private static function acl_path($name)
	{
		return EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $name . '_acl';
	}

	private static function htpasswd_users($name)
	{
		$users = [];
		foreach ( preg_split( '/\R/', self::read_file( self::htpasswd_path( $name ) ) ) as $line ) {
			if ( false !== strpos( $line, ':' ) ) {
				$users[] = substr( $line, 0, strpos( $line, ':' ) );
			}
		}

		return $users;
	}

	/**
	 * `htpasswd -v` only reads the file. Runs inside the proxy, which owns the htpasswd volume.
	 */
	private function verify_htpasswd($name, $user, $pass)
	{
		$name = $this->replace_variables( $name );
		self::read_file( self::htpasswd_path( $name ) );

		return EE::launch(
			sprintf(
				'docker exec %s htpasswd -vb %s %s %s',
				escapeshellarg( EE_PROXY_TYPE ),
				escapeshellarg( '/etc/nginx/htpasswd/' . $name ),
				escapeshellarg( $this->replace_variables( $user ) ),
				escapeshellarg( $this->replace_variables( $pass ) )
			),
			false,
			true
		);
	}

	private function assert_http_status($url, $host, $resolve_ip, $user, $pass, $code, $timeout = self::HTTP_SETTLE_SECONDS)
	{
		$url  = $this->replace_variables( $url );
		$args = [ '-sS', '-k', '--max-time', '15', '-o', '-', '-w', '\n%{http_code}' ];
		if ( null !== $host ) {
			$args[] = '-H';
			$args[] = 'Host: ' . $this->replace_variables( $host );
		}
		if ( null !== $resolve_ip ) {
			$parts  = parse_url( $url );
			$port   = isset( $parts['port'] ) ? $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			$args[] = '--resolve';
			$args[] = $parts['host'] . ':' . $port . ':' . $this->replace_variables( $resolve_ip );
		}
		if ( null !== $user ) {
			$args[] = '-u';
			$args[] = $this->replace_variables( $user ) . ':' . $this->replace_variables( $pass );
		}
		$args[]  = $url;
		$command = 'curl ' . implode( ' ', array_map( 'escapeshellarg', $args ) );

		$deadline = time() + $timeout;
		do {
			$result = EE::launch( $command, false, true );
			$lines  = explode( "\n", rtrim( $result->stdout ) );
			$actual = array_pop( $lines );
			if ( (string) $actual === (string) $code ) {
				return;
			}
			if ( time() >= $deadline ) {
				break;
			}
			sleep( 1 );
		} while ( true );

		throw new Exception(
			sprintf(
				"GET %s%s%s returned %s, expected %s (retried for %ds).\nBody (first 500 bytes):\n%s\ncurl STDERR: %s",
				$url,
				null !== $host ? " (Host: $host)" : '',
				null !== $user ? " as $user" : '',
				$actual,
				$code,
				$timeout,
				substr( implode( "\n", $lines ), 0, 500 ),
				$result->stderr
			)
		);
	}

	/* ------------------------ Auth state management ---------------------- */

	private static function prefixed_sites_with_auth_rows()
	{
		$like  = self::SITE_PREFIX . '%';
		$sites = array_merge(
			array_column( self::db_rows( 'SELECT DISTINCT site_url FROM auth_users WHERE site_url LIKE ?', [ $like ] ), 'site_url' ),
			array_column( self::db_rows( 'SELECT DISTINCT site_url FROM auth_ips WHERE site_url LIKE ?', [ $like ] ), 'site_url' )
		);

		return array_values( array_unique( $sites ) );
	}

	/**
	 * Removes a test site's auth users and whitelist through ee; falls back to direct cleanup
	 * when ee refuses (site deleted or disabled).
	 */
	private static function reset_site_auth($site)
	{
		self::assert_prefixed_site( $site );
		if ( self::db_rows( 'SELECT id FROM auth_users WHERE site_url = ?', [ $site ] ) ) {
			self::ee( 'auth delete ' . escapeshellarg( $site ) );
		}
		if ( self::db_rows( 'SELECT id FROM auth_ips WHERE site_url = ?', [ $site ] ) ) {
			self::ee( 'auth delete ' . escapeshellarg( $site ) . ' --ip' );
		}

		$left_users = self::db_rows( 'SELECT id FROM auth_users WHERE site_url = ?', [ $site ] );
		$left_ips   = self::db_rows( 'SELECT id FROM auth_ips WHERE site_url = ?', [ $site ] );
		$acl_file   = self::acl_path( $site );
		$acl_stale  = file_exists( $acl_file ) && ! self::site_row( $site );
		if ( $left_users || $left_ips || $acl_stale ) {
			self::db()->prepare( 'DELETE FROM auth_users WHERE site_url = ?' )->execute( [ $site ] );
			self::db()->prepare( 'DELETE FROM auth_ips WHERE site_url = ?' )->execute( [ $site ] );
			@unlink( self::htpasswd_path( $site ) );
			@unlink( $acl_file );
			self::reload_proxy( false );
		}
	}

	private static function cleanup_prefixed_sites()
	{
		$like = self::SITE_PREFIX . '%';
		foreach ( self::db_rows( 'SELECT site_url FROM sites WHERE site_url LIKE ?', [ $like ] ) as $row ) {
			self::ee( 'site delete ' . escapeshellarg( $row['site_url'] ) . ' --yes' );
		}

		// Leftovers of known bugs (AUTH-11: site delete keeps the _acl file).
		$changed = false;
		foreach ( [ 'auth_users', 'auth_ips' ] as $table ) {
			$statement = self::db()->prepare( "DELETE FROM $table WHERE site_url LIKE ?" );
			$statement->execute( [ $like ] );
			$changed = $changed || $statement->rowCount() > 0;
		}
		$files = array_merge(
			glob( self::htpasswd_path( self::SITE_PREFIX . '*' ) ) ?: [],
			glob( self::acl_path( self::SITE_PREFIX . '*' ) ) ?: []
		);
		foreach ( $files as $file ) {
			$changed = unlink( $file ) || $changed;
		}
		if ( $changed ) {
			self::reload_proxy( false );
		}
	}

	/**
	 * Safety net for a failed restore: removes global users named bh-* and documentation-range global IPs.
	 */
	private static function cleanup_leftover_global_test_auth()
	{
		foreach ( self::db_rows( "SELECT username FROM auth_users WHERE site_url = 'default' AND username LIKE 'bh-%'" ) as $row ) {
			self::ee( 'auth delete global --user=' . escapeshellarg( $row['username'] ) );
		}
		foreach ( self::db_rows( "SELECT ip FROM auth_ips WHERE site_url = 'default'" ) as $row ) {
			if ( preg_match( '#^(192\.0\.2\.|198\.51\.100\.|203\.0\.113\.|2001:db8:)#i', $row['ip'] ) ) {
				self::ee( 'auth delete global --ip=' . escapeshellarg( $row['ip'] ) );
			}
		}
	}

	private static function global_auth_state()
	{
		return [
			'users' => self::db_rows( "SELECT site_url, username, password FROM auth_users WHERE site_url IN ('default', 'default_admin_tools') ORDER BY id" ),
			'ips'   => array_column( self::db_rows( "SELECT ip FROM auth_ips WHERE site_url = 'default' ORDER BY id" ), 'ip' ),
		];
	}

	/**
	 * Puts back the global and admin-tools users (same passwords) and the global whitelist, then
	 * regenerates the files the way auth-command does.
	 */
	private static function restore_global_auth_state(array $snapshot)
	{
		if ( self::global_auth_state() == $snapshot ) {
			return;
		}

		$pdo = self::db();
		$pdo->beginTransaction();
		$pdo->exec( "DELETE FROM auth_users WHERE site_url IN ('default', 'default_admin_tools')" );
		$pdo->exec( "DELETE FROM auth_ips WHERE site_url = 'default'" );
		foreach ( $snapshot['users'] as $row ) {
			self::insert_row( 'auth_users', $row );
		}
		foreach ( $snapshot['ips'] as $ip ) {
			self::insert_row( 'auth_ips', [ 'site_url' => 'default', 'ip' => $ip ] );
		}
		$pdo->commit();

		self::regenerate_auth_files();
	}

	/**
	 * Mirrors auth-command's generate_*_auth_files() / generate_*_whitelist() for every scope.
	 */
	private static function regenerate_auth_files()
	{
		$global = self::db_rows( "SELECT username, password FROM auth_users WHERE site_url = 'default' ORDER BY id" );
		self::write_htpasswd( 'default_admin_tools', self::db_rows( "SELECT username, password FROM auth_users WHERE site_url = 'default_admin_tools' ORDER BY id" ) );
		self::write_htpasswd( 'default', $global );
		foreach ( self::db_rows( "SELECT DISTINCT site_url FROM auth_users WHERE site_url NOT IN ('default', 'default_admin_tools')" ) as $row ) {
			$own = self::db_rows( 'SELECT username, password FROM auth_users WHERE site_url = ? ORDER BY id', [ $row['site_url'] ] );
			self::write_htpasswd( $row['site_url'], array_merge( $global, $own ) );
		}

		$global_ips = array_column( self::db_rows( "SELECT ip FROM auth_ips WHERE site_url = 'default' ORDER BY id" ), 'ip' );
		self::write_acl( 'default', $global_ips );
		foreach ( self::db_rows( "SELECT DISTINCT site_url FROM auth_ips WHERE site_url != 'default'" ) as $row ) {
			$own = array_column( self::db_rows( 'SELECT ip FROM auth_ips WHERE site_url = ? ORDER BY id', [ $row['site_url'] ] ), 'ip' );
			self::write_acl( $row['site_url'], array_merge( $global_ips, $own ) );
		}

		self::reload_proxy();
	}

	private static function write_htpasswd($name, array $rows)
	{
		$path = self::htpasswd_path( $name );
		if ( empty( $rows ) ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}

			return;
		}
		foreach ( array_values( $rows ) as $i => $row ) {
			$result = EE::launch(
				sprintf(
					'docker exec %s htpasswd -%s %s %s %s',
					escapeshellarg( EE_PROXY_TYPE ),
					0 === $i ? 'bc' : 'b',
					escapeshellarg( '/etc/nginx/htpasswd/' . $name ),
					escapeshellarg( $row['username'] ),
					escapeshellarg( $row['password'] )
				),
				false,
				true
			);
			if ( 0 !== $result->return_code ) {
				throw new Exception( "Could not write htpasswd/$name.\n" . self::describe( $result ) );
			}
		}
	}

	private static function write_acl($name, array $ips)
	{
		$path = self::acl_path( $name );
		if ( empty( $ips ) ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}

			return;
		}
		$content = 'satisfy any;' . PHP_EOL;
		foreach ( $ips as $ip ) {
			$content .= "allow $ip;" . PHP_EOL;
		}
		file_put_contents( $path, $content . 'deny all;' );
	}

	/**
	 * Same sequence as site-command's reload_global_nginx_proxy().
	 */
	private static function reload_proxy($strict = true)
	{
		$proxy = escapeshellarg( EE_PROXY_TYPE );
		EE::launch( "docker exec $proxy sh -c '/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf'", false, true );
		$test = EE::launch( "docker exec $proxy nginx -t", false, true );
		if ( 0 !== $test->return_code ) {
			if ( $strict ) {
				throw new Exception( "nginx -t failed after restoring auth state.\n" . self::describe( $test ) );
			}

			return;
		}
		EE::launch( "docker exec $proxy nginx -s reload", false, true );
	}
}
