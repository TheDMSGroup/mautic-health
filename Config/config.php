<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

return [
    'name'        => 'Health',
    'description' => 'Checks the health of the Mautic instance.',
    'version'     => '1.0',
    'author'      => 'Mautic',

    'services' => [
        'models'       => [
            'mautic.health.model.health' => [
                'class'     => 'MauticPlugin\MauticHealthBundle\Model\HealthModel',
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.helper.integration',
                    'mautic.campaign.model.campaign',
                    'mautic.campaign.model.event',
                ],
            ],
        ],
    ],
];
