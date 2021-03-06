<?php

namespace Waffle\Model\Cli\Runner;

use Exception;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Waffle\Helper\WaffleHelper;
use Waffle\Model\Cli\DrushCommand;

class Drush extends BaseRunner
{

    /**
     * @var string
     */
    private $drush_major_version;

    /**
     * @var string
     */
    private $drupal_major_version;

    /**
     * @var array
     */
    private $drush_status_data;

    /**
     * @var boolean
     */
    private $drush_patching_enabled = false;

    /**
     * @var WaffleHelper
     */
    private $waffleHelper;

    /**
     *  Constructor
     *
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();

        $this->waffleHelper = new WaffleHelper();

        // Calling 'drush status --format=json' will give us a json blob that
        // we can parse to get info about the site.
        $status = new DrushCommand(['status', '--format=json']);
        $process = $status->getProcess();
        $process->run();
        $json = $process->getOutput();

        $this->drush_status_data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Unable to derive Drush and Drupal details from output of `drush status`.');
        }

        if (isset($this->drush_status_data['drush-version'])) {
            $parts = explode('.', $this->drush_status_data['drush-version']);
            $this->drush_major_version = $parts[0];
        } else {
            throw new Exception('Unable to derive Drush version.');
        }

        if (isset($this->drush_status_data['drupal-version'])) {
            $parts = explode('.', $this->drush_status_data['drupal-version']);
            $this->drupal_major_version = $parts[0];
        } else {
            throw new Exception('Unable to derive Drupal version.');
        }

        // For D7, checks if Drush patching is enabled.
        $this->drush_patching_enabled = $this->checkDrushPatchingEnabled();

        // Attempt a DB connection / verify that local settings are present.
        $this->validate();
    }

    /**
     * Gets the major version of Drush in use.
     *
     * @return string
     */
    public function getDrushMajorVersion()
    {
        return $this->drush_major_version;
    }

    /**
     * Gets the major version of Drupal in use.
     *
     * @return string
     */
    public function getDrupalMajorVersion()
    {
        return $this->drupal_major_version;
    }

    /**
     * Gets the Drush status data.
     *
     * @return array
     */
    public function getDrushStatusData()
    {
        return $this->drush_status_data;
    }

    /**
     * Returns boolean for Drush patching.
     *
     * @return boolean
     */
    public function getDrushPatchingEnabled()
    {
        return $this->drush_patching_enabled;
    }

    /**
     * Resets the local database.
     *
     * @return Process
     */
    private function resetDatabase()
    {
        $db_reset = new DrushCommand(['sql-create', '-y']);
        $process = $db_reset->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Gets a database dump from the provided alias.
     *
     * @return string
     */
    private function getDatabaseDump($alias)
    {
        $fname = sprintf('%s.sql', $alias);
        $dump = $this->waffleHelper->getTempFilePath($fname);

        // Using DrushCommand to build as it will handle prefixes in the future.
        $drush =  new DrushCommand([$alias, 'sql-dump']);
        $export = $drush->getProcess()->getCommandLine();

        // This is a hack. The --result-file flag is sent to the upstream, so
        // we are opting to redirect the ouput.
        // TODO: Think through other options that are more cross-platform.
        $process = Process::fromShellCommandline(sprintf('%s > %s', $export, $dump));
        $process->run();

        if (!file_exists($dump)) {
            throw new Exception('Database dump failed: ' . $process->getOutput());
        }

        return $dump;
    }

    /**
     * Imports dumped sql into the database.
     *
     * @return string
     */
    private function importDatabase($dump)
    {
        // Using DrushCommand to build as it will handle prefixes in the future.
        $drush = new DrushCommand(['sql-cli']);
        $import = $drush->getProcess()->getCommandLine();

        // Feeding the dump file in with <.
        // TODO: Think through other options that are more cross-platform.
        $process = Process::fromShellCommandline(sprintf('%s < %s', $import, $dump));
        $process->run();

        // Doing some cleanup.
        unlink($dump);

        return $process;
    }

    /**
     * Syncs the database from the remote alias to local.
     *
     * @return Process
     */
    public function syncDatabase($alias)
    {
        // TODO Add support for sql-sync. Perhaps an $strategy that is passed.
        $this->resetDatabase();
        $dump = $this->getDatabaseDump($alias);
        $this->importDatabase($dump);
        $this->clearCaches();
    }

    /**
     * Downloads the files for Drupal sites.
     *
     * @return Process
     */
    public function syncFiles($alias)
    {
        $file_sync = new DrushCommand(['-y', 'core-rsync', $alias, 'sites/default/files']);
        $process = $file_sync->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Attempts to log user into the local site.
     *
     * @return Process
     */
    public function userLogin()
    {
        $uli = new DrushCommand(['uli']);
        $process = $uli->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Clears caches for Drupal sites.
     *
     * @return Process
     */
    public function clearCaches()
    {
        $cc = [];

        switch ($this->drupal_major_version) {
            case '7':
                $cc = ['cc', 'all'];
                break;
            case '8':
                $cc = ['cr'];
                break;
            default:
                throw new Exception(
                    sprintf('Clearing caches with Drush for Drupal %s not supported.', $this->drupal_major_version)
                );
        }

        $cache_clear = new DrushCommand($cc);
        $process = $cache_clear->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Checks for pending security (Drush 9) and non-security (Drush 8) updates.
     *
     * @param string $format
     * @return Process
     * @throws Exception
     */
    public function pmSecurity($format = 'table')
    {
        switch ($this->drush_major_version) {
            case '8':
                $args = ['ups', '--check-disabled', "--format={$format}"];
                break;
            case '9':
            case '10':
                $args = ['pm:security', "--format={$format}"];
                break;
            default:
                throw new Exception(
                    sprintf(
                        'Checking pending updates with Drush for Drush %s not supported.',
                        $this->drush_major_version
                    )
                );
        }

        $pm_security = new DrushCommand($args);
        $process = $pm_security->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Runs drush update-code for a module.
     *
     * @param $module
     *
     * @return Process
     * @throws Exception
     */
    public function updateCode($module): Process
    {
        if ($this->drupal_major_version !== '7') {
            throw new Exception(
                sprintf(
                    'Drush update-code is not supported for Drupal %s.',
                    $this->drupal_major_version
                )
            );
        }

        $command = new DrushCommand(['upc', $module, '--check-disabled', '-y']);
        $process = $command->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Runs any pending database updates.
     *
     * @return Process
     * @throws Exception
     */
    public function updateDatabase()
    {
        $updb = new DrushCommand(['updb', '-y']);
        $process = $updb->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Exports any changed config back to the filesystem.
     *
     * @param string $config_key
     * @return Process
     * @throws Exception
     */
    public function configExport($config_key = 'sync')
    {
        if ($this->drupal_major_version <= 7) {
            throw new Exception(
                sprintf('Exporting config with Drush for Drupal %s not supported.', $this->drupal_major_version)
            );
        }

        $cex = new DrushCommand(['cex', '-y', $config_key]);
        $process = $cex->getProcess();
        $process->run();
        return $process;
    }

    /**
     *
     * @param $module
     *
     * @return false|Process
     * @throws Exception
     */
    public function patchApply($module)
    {
        if (!$this->getDrushPatchingEnabled()) {
            return false;
        }

        $command = new DrushCommand(['pp', $module, '-y']);
        $process = $command->getProcess();
        $process->run();
        return $process;
    }

    /**
     * Validates Drush configuration.
     *
     * @return void
     * @throws Exception
     */
    private function validate()
    {
        // Tries to ensure that local settings is present.
        $this->ensureLocalSettings();

        // Attempts a DB connection.
        $this->validateDbAccess();
    }

    /**
     * Validates database access.
     *
     * @return void
     * @throws Exception
     */
    private function validateDbAccess()
    {
        $status = new DrushCommand(['sql:query', 'select 1']);
        $process = $status->getProcess();
        $process->run();
        $output = $process->getOutput();

        // The sql:query command returns a 1 even if unsuccessful. Explicitly
        // checking the output.
        if (trim($output) !== '1') {
            $error = 'Unable to connect to database. Make sure that your local ';
            $error .= 'settings file is present and has valid database connection details.';
            throw new Exception($error);
        }
    }

    /**
     * Checks for the local settings file. Will attempt to create it if needed.
     *
     * @return void
     * @throws Exception
     */
    private function ensureLocalSettings()
    {
        // Look for local settings file.
        $finder = new Finder();
        $finder->files();
        $finder->in(getcwd());
        $filename = $this->config->getLocalSettingsFilename();
        $finder->name($filename);

        if ($finder->hasResults()) {
            // TODO: Consider hashing the contents of the example file and the
            // present file. If different, emit a warning.
            return;
        }

        $this->io->warning('Local settings not found, copying from example.settings.local.php');

        // Attempt to add local settings file from example.
        $finder = new Finder();
        $finder->files();
        $finder->in(getcwd());
        $finder->name('example.settings.local.php');

        if (!$finder->hasResults()) {
            throw new Exception('Unable to find example.settings.local.php file.');
        }

        $iterator = $finder->getIterator();
        $iterator->rewind();
        $file = $iterator->current();
        $example_settings = $file->getRealPath();
        $local_settings = $file->getPath() . '/default/settings.local.php';

        if (!copy($example_settings, $local_settings)) {
            throw new Exception('Unable to create settings.local.php');
        }
    }

    /**
     * Checks if Drush patching is available.
     *
     * @return boolean
     * @throws Exception
     */
    private function checkDrushPatchingEnabled(): bool
    {
        if ($this->drupal_major_version !== '7') {
            return false;
        }

        $status = new DrushCommand(['help', 'patch-status']);
        $process = $status->getProcess();
        $process->run();

        $patching_enabled = $process->isSuccessful();

        if (!$patching_enabled) {
            $msg = 'Drush patching is not enabled. Consider settting up the project with a patches.make file. ';
            $msg .= 'More details here: https://github.com/davereid/drush-patchfile';
            $this->io->warning($msg);
        }

        return $patching_enabled;
    }
}
