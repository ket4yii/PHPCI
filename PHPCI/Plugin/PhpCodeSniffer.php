<?php
/**
 * PHPCI - Continuous Integration for PHP
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
 * @link         https://www.phptesting.org/
 */

namespace PHPCI\Plugin;

use PHPCI;
use PHPCI\Builder;
use PHPCI\Model\Build;

/**
* PHP Code Sniffer Plugin - Allows PHP Code Sniffer testing.
* @author       Dan Cryer <dan@block8.co.uk>
* @package      PHPCI
* @subpackage   Plugins
*/
class PhpCodeSniffer extends AbstractExecutingPlugin implements PHPCI\ZeroConfigPlugin
{
    /**
     * @var array
     */
    protected $suffixes;

    /**
     * @var string
     */
    protected $directory;

    /**
     * @var string
     */
    protected $standard;

    /**
     * @var string
     */
    protected $tab_width;

    /**
     * @var string
     */
    protected $encoding;

    /**
     * @var int
     */
    protected $allowed_errors;

    /**
     * @var int
     */
    protected $allowed_warnings;

    /**
     * @var string, based on the assumption the root may not hold the code to be
     * tested, exteds the base path
     */
    protected $path;

    /**
     * Check if this plugin can be executed.
     * @param $stage
     * @param Builder $builder
     * @param Build $build
     * @return bool
     */
    public static function canExecute($stage, Builder $builder, Build $build)
    {
        if ($stage == 'test') {
            return true;
        }

        return false;
    }

    /**
     * Configure the plugin.
     *
     * @param array $options
     */
    protected function setOptions(array $options)
    {
        $this->suffixes = array('php');
        $this->directory = $this->buildPath;
        $this->standard = 'PSR2';
        $this->tab_width = '';
        $this->encoding = '';
        $this->path = '';
        $this->allowed_warnings = 0;
        $this->allowed_errors = 0;

        if (isset($options['zero_config']) && $options['zero_config']) {
            $this->allowed_warnings = -1;
            $this->allowed_errors = -1;
        }

        if (isset($options['suffixes'])) {
            $this->suffixes = (array)$options['suffixes'];
        }

        if (!empty($options['tab_width'])) {
            $this->tab_width = ' --tab-width='.$options['tab_width'];
        }

        if (!empty($options['encoding'])) {
            $this->encoding = ' --encoding=' . $options['encoding'];
        }

        foreach (array('directory', 'standard', 'path', 'ignore', 'allowed_warnings', 'allowed_errors') as $key) {
            if (array_key_exists($key, $options)) {
                $this->{$key} = $options[$key];
            }
        }
    }

    /**
    * Runs PHP Code Sniffer in a specified directory, to a specified standard.
    */
    public function execute()
    {
        list($ignore, $standard, $suffixes) = $this->getFlags();

        $phpcs = $this->executor->findBinary('phpcs');

        $this->executor->setQuiet(true);

        $cmd = $phpcs . ' --report=json %s %s %s %s %s "%s"';
        $this->executor->executeCommand(
            $cmd,
            $standard,
            $suffixes,
            $ignore,
            $this->tab_width,
            $this->encoding,
            $this->buildPath . $this->path
        );

        $output = $this->executor->getLastOutput();
        list($errors, $warnings, $data) = $this->processReport($output);

        $this->executor->setQuiet(false);

        $success = true;
        $this->build->storeMeta('phpcs-warnings', $warnings);
        $this->build->storeMeta('phpcs-errors', $errors);
        $this->build->storeMeta('phpcs-data', $data);

        if ($this->allowed_warnings != -1 && $warnings > $this->allowed_warnings) {
            $success = false;
        }

        if ($this->allowed_errors != -1 && $errors > $this->allowed_errors) {
            $success = false;
        }

        return $success;
    }

    /**
     * Process options and produce an arguments string for PHPCS.
     * @return array
     */
    protected function getFlags()
    {
        $ignore = '';
        if (count($this->ignore)) {
            $ignore = ' --ignore=' . implode(',', $this->ignore);
        }

        if (strpos($this->standard, '/') !== false) {
            $standard = ' --standard='.$this->directory.$this->standard;
        } else {
            $standard = ' --standard='.$this->standard;
        }

        $suffixes = '';
        if (count($this->suffixes)) {
            $suffixes = ' --extensions=' . implode(',', $this->suffixes);
        }

        return array($ignore, $standard, $suffixes);
    }

    /**
     * Process the PHPCS output report.
     * @param $output
     * @return array
     * @throws \Exception
     */
    protected function processReport($output)
    {
        $data = json_decode(trim($output), true);

        if (!is_array($data)) {
            $this->logger->log($output);
            throw new \Exception(PHPCI\Helper\Lang::get('could_not_process_report'));
        }

        $errors = $data['totals']['errors'];
        $warnings = $data['totals']['warnings'];

        $rtn = array();

        foreach ($data['files'] as $fileName => $file) {
            $fileName = str_replace($this->buildPath, '', $fileName);

            foreach ($file['messages'] as $message) {
                $this->build->reportError($this->phpci, $fileName, $message['line'], 'PHPCS: ' . $message['message']);

                $rtn[] = array(
                    'file' => $fileName,
                    'line' => $message['line'],
                    'type' => $message['type'],
                    'message' => $message['message'],
                );
            }
        }

        return array($errors, $warnings, $rtn);
    }
}
