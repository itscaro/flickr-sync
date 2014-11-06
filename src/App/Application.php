<?php

namespace Itscaro\App;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Yaml\Yaml;

final class Application extends SymfonyApplication {

    private $_config;

    /**
     * Returns configuration array
     * @return array
     */
    public function getConfig() {
        return $this->_config;
    }

    /**
     * Set configuration array
     * @param array $config
     * @return Application
     */
    public function setConfig(array $config) {
        $this->_config = $config;
        return $this;
    }

    /**
     * Load YAML config file
     * @param string $filepath
     */
    public function loadConfigFromFile($filepath) {
        $config = Yaml::parse($filepath);
        $this->setConfig($config);
    }

}
