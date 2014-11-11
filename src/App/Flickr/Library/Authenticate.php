<?php

namespace Itscaro\App\Flickr\Library;

use Monolog\Logger;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Question;
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
     * @var Logger
     */
    protected $_logger;

    public function __construct(Input $input, Output $output, Logger $logger)
    {
        $this->_input = $input;
        $this->_output = $output;
        $this->_logger = $logger;
    }

    /**
     * 
     * @param array $configOauth
     * @param array $configHttpClient
     * @return Access
     */
    public function authenticate(array $configOauth, array $configHttpClient)
    {
        // Instantiate a client object
        $httpClient = new ZendHttpClient('', $configHttpClient);
        Consumer::setHttpClient($httpClient);

        // Oauth client
        $consumer = new Consumer($configOauth);
        $requestToken = $consumer->getRequestToken();

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
