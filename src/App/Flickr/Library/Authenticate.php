<?php

namespace Itscaro\App\Flickr\Library;

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

class Authenticate {

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
     * @var \Monolog\Logger
     */
    protected $_logger;

    public function __construct(Input $input, Output $output, \Monolog\Logger $logger)
    {
        $this->_input = $input;
        $this->_output = $output;
        $this->_logger = $logger;
    }

    /**
     * 
     * @param array $configOauth
     * @param array $configHttpClient
     * @return \ZendOAuth\Token\Access
     */
    public function authenticate(array $configOauth, array $configHttpClient)
    {
        // Instantiate a client object
        $httpClient = new ZendHttpClient('', $configHttpClient);
        Consumer::setHttpClient($httpClient);

        // Oauth client
        $consumer = new Consumer($configOauth);

        //var_dump($consumer->getRequestTokenUrl());

        $requestToken = $consumer->getRequestToken();
        //var_dump($requestToken->getToken());

        $this->_output->writeln("<info>Please open this URL:</info>");
        $authorizationUrl = $consumer->getRedirectUrl(array(
            'perms' => 'delete'
        ));
        $this->_output->writeln("<info>" . $authorizationUrl . "</info>");

        $helper = $this->getHelper('question');
        $question = new Question\Question('<question>Confirmation code:</question>');
        $response = $helper->ask($this->_input, $this->_output, $question);

        $accessToken = null;
        if ($response) {
            $queryData = array(
                'oauth_token' => $requestToken->getToken(),
                'oauth_verifier' => $response,
            );

            $accessToken = $consumer->getAccessToken($queryData, $requestToken);
        }
        
        return $accessToken;
    }

}
