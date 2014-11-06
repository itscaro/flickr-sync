<?php

namespace Itscaro\App\Flickr;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

class Sync extends Command
{

    protected function configure()
    {
        $this->setName("flickr:sync")
            ->setDescription("")
            ->setDefinition(array())
            ->setHelp(<<<EOT
EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $settings = $this->_getDataStore();
        $api_key = "ef41e52674a4572bd9c05c1df0a3c9a4";
        $api_secret = "077c25b4411d432c";

        $configOauth = array(
            'siteUrl' => 'https://www.flickr.com/services/oauth/',
            'consumerKey' => 'ef41e52674a4572bd9c05c1df0a3c9a4',
            'consumerSecret' => '077c25b4411d432c',
        );
        $configHttpClient = array(
            'adapter' => 'Zend\Http\Client\Adapter\Curl',
            'sslverifypeer' => false
        );

        if (!isset($settings['accessToken'])) {
            // Instantiate a client object
            $httpClient = new \Zend\Http\Client('', $configHttpClient);
            \ZendOAuth\Consumer::setHttpClient($httpClient);

            // Oauth client
            $consumer = new \ZendOAuth\Consumer($configOauth);

            //var_dump($consumer->getRequestTokenUrl());

            $requestToken = $consumer->getRequestToken();
            //var_dump($requestToken->getToken());

            echo "Please open this URL: \n";
            echo $consumer->getRedirectUrl(array(
                'perms' => 'delete'
            ));
            echo "\n";

            $helper = $this->getHelper('question');
            $question = new Question\Question('Confirmation code:');
            $response = $helper->ask($input, $output, $question);

            if ($response) {
                $queryData = array(
                    'oauth_token' => $requestToken->getToken(),
                    'oauth_verifier' => $response,
                );
            } else {
                return;
            }

            $accessToken = $consumer->getAccessToken($queryData, $requestToken);

            $this->_setDataStore(array(
                'accessToken' => $accessToken
            ));
        } else {
            $flickr = new \Itscaro\Service\Flickr\Flickr($configOauth, $configHttpClient);
            $flickr->setAccessToken($settings['accessToken']);

            $flickrMulti = new \Itscaro\Service\Flickr\ClientMulti('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
            $flickrMulti->setAccessToken($settings['accessToken']);

            $flickrSimple = new \Itscaro\Service\Flickr\Client('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
            $flickrSimple->setAccessToken($settings['accessToken']);

//            $result = $flickrSimple->get('flickr.photosets.getList', array(
//                'user_id' => "10995091@N00"
//            ));
//            var_dump($result);
//
//            $flickrMulti->addToQueue('GET', 'flickr.photosets.getList', array(
//                'user_id' => "10995091@N00"
//            ));
//            $result = $flickrMulti->dispatchMulti();
//            var_dump($result);
//
//            $flickr->photosetGetList("10995091@N00");
//            $result = $flickr->dispatch();
//            var_dump($result);

            $flickrUploader = new \Itscaro\Service\Flickr\Photo($settings['accessToken'], $configOauth, $configHttpClient);
            $file = ROOTDIR . '/data/useravatar.png';

            $id = $flickrUploader->uploadSync($file);
            echo $file . " - " . $id . "\n";

            $id = $flickrUploader->uploadAsync($file);
            echo $file . " - " . $id . "\n";

//            $params = $this->_prepareParamsForProcess('by-taken-year', $settings['accessToken']->getParam('user_nsid'), 1);
//            $response = $flickrMulti->dispatch('GET', 'flickr.photosets.getPhotos', $params);
//
//            var_dump($response);
        }

        echo "\n\nDone\n";
    }

    protected function _scan($dir)
    {
        $finder = new \Symfony\Component\Finder\Finder();
        $finder->in($dir)
            ->name('\.(jpg|jpeg|png|gif|tif|tiff)$');

        foreach ($finder as $file) {
            /* @var $file \Symfony\Component\Finder\SplFileInfo */
            $file->getBasename();
            
        }
    }

    protected function _getDataStore()
    {

        //Define your file path based on the cache one
        $filename = ROOTDIR . '/data/store';

        //Create your own folder in the cache directory
        $fs = new Filesystem();
        try {
            $fs->mkdir(dirname($filename));
        } catch (IOException $e) {
            echo "An error occured while creating your directory";
        }

        if ($fs->exists($filename)) {
            return unserialize(file_get_contents($filename));
        } else {
            return array();
        }
    }

    protected function _setDataStore(array $data)
    {
        //Define your file path based on the cache one
        $filename = ROOTDIR . '/data/store';

        //Create your own folder in the cache directory
        $fs = new Filesystem();
        try {
            $fs->mkdir(dirname($filename));
        } catch (IOException $e) {
            echo "An error occured while creating your directory";
        }

        if (!$fs->exists($filename)) {
            $fs->touch($filename);
        }

        return file_put_contents($filename, serialize($data));
    }

}
