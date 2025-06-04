<?php

namespace Growthbook\Plugins;

use Growthbook\Growthbook;

interface PluginInterface
{
    /**
     * @param Growthbook $growthbook
     */
    public function initialize(Growthbook $growthbook): void;
}
