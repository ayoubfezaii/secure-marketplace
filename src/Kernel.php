<?php

/**
 * FILE: src/Kernel.php
 * WHAT: the Symfony kernel = the object that starts the application.
 * ROLE IN THE PROJECT: loads the bundles (config/bundles.php), the configuration (config/packages/*.yaml) and
 *   the routes. Standard Symfony file, nothing project-specific.
 * USED BY: public/index.php (web requests) and bin/console (commands).
 */

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/** The application kernel. All the work is done by MicroKernelTrait (provided by Symfony). */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
