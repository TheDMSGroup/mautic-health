<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\Command;

use Mautic\CoreBundle\Command\ModeratedCommand;
use MauticPlugin\MauticHealthBundle\Model\HealthModel;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI Command : Performs maintenance tasks required by the health plugin.
 *
 * php app/console mautic:contactclient:maintenance
 */
class HealthCommand extends ModeratedCommand
{
    /**
     * Maintenance command line task.
     */
    protected function configure()
    {
        $this->setName('mautic:health:check')
            ->setDescription('General all purpose health check.');

        parent::configure();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container  = $this->getContainer();
        $translator = $container->get('translator');
        if (!$this->checkRunStatus($input, $output)) {
            return 0;
        }

        /** @var HealthModel $healthModel */
        $healthModel = $container->get('mautic.health.model.health');
        $output->writeln(
            '<info>'.$translator->trans(
                'mautic.health.running'
            ).'</info>'
        );
        $healthModel->campaignRebuildCheck($output);
        $healthModel->campaignTriggerCheck($output);
        $healthModel->getIncidents($output);
        $output->writeln(
            '<info>'.$translator->trans(
                'mautic.health.complete'
            ).'</info>'
        );

        $this->completeRun();

        return 0;
    }
}
