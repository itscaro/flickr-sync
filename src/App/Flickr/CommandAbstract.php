<?php

namespace Itscaro\App\Flickr;

use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Shell\Command;
use ZendOAuth\Token\Access;

class CommandAbstract extends Command {

    /**
     *
     * @var Input
     */
    protected $_input;

    /**
     *
     * @var Output
     */
    protected $_output;

    /**
     *
     * @var Access
     */
    protected $_accessToken;

    /**
     *
     * @var Logger
     */
    protected $_logger;

    /**
     * List of supported extensions
     * @var array
     */
    protected $_supportedExtensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'tif',
        'tiff'
    );

    protected function preExecute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;

        $app = $this->getApplication();
        /* @var $app Application */
        $this->_logger = $app->getLogger();

        $this->_logger->info('Application started');
    }

    protected function postExecute(InputInterface $input, OutputInterface $output, array $params = array())
    {
        $this->_logger->info('Done in ' . round((microtime(1) - $params['startTime']), 1) . ' seconds');
    }

    protected function sanitizeTag($string)
    {
        return preg_replace('/[^a-z0-9-_\/]/i', '_', $string);
    }

}
