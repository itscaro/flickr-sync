<?php

namespace Itscaro\App\Flickr;

use Itscaro\App\Application;
use Itscaro\Service\Flickr\Client;
use Itscaro\Service\Flickr\ClientMulti;
use Itscaro\Service\Flickr\Photo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Zend\Http\Client as ZendHttpClient;
use ZendOAuth\Consumer;
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
     * @var \Monolog\Logger
     */
    protected $_logger;

    protected function _preExecute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;

        $app = $this->getApplication();
        /* @var $app Application */
        $this->_logger = $app->getLogger();

        $this->_logger->info('Application started');
    }

    protected function _postExecute(InputInterface $input, OutputInterface $output, array $params = array())
    {
        $this->_logger->info('Done in ' . round((microtime(1) - $params['startTime']), 1));
    }

}
