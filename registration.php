<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth Error Monitor — smart error management for Magento 2.
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Panth_ErrorMonitor',
    __DIR__
);
