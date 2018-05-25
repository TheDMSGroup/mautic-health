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
use Symfony\Component\Console\Input\InputOption;
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
            ->setDescription('General all purpose health check.')
            ->addOption(
                'campaign-rebuild-threshold',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of contacts waiting to be ingested into a campaign from a segment.'
            )
            ->addOption(
                'campaign-trigger-threshold',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of contacts waiting for scheduled campaign events to fire which are late.'
            );

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
        $verbose                  = $input->getOption('verbose');
        $campaignRebuildThreshold = $input->getOption('campaign-rebuild-threshold');
        $campaignTriggerThreshold = $input->getOption('campaign-trigger-threshold');
        $quiet                    = $input->getOption('quiet');
        $container                = $this->getContainer();
        $translator               = $container->get('translator');

        if (!$this->checkRunStatus($input, $output)) {
            return 0;
        }

        /** @var HealthModel $healthModel */
        $healthModel = $container->get('mautic.health.model.health');
        if ($verbose) {
            $output->writeln(
                '<info>'.$translator->trans(
                    'mautic.health.running'
                ).'</info>'
            );
        }
        $settings = [];
        if ($campaignRebuildThreshold) {
            $settings['campaign_rebuild_threshold'] = $campaignRebuildThreshold;
        }
        if ($campaignTriggerThreshold) {
            $settings['campaign_trigger_threshold'] = $campaignTriggerThreshold;
        }
        if ($settings) {
            $healthModel->setSettings($settings);
        }
        $healthModel->campaignRebuildCheck($output, $verbose);
        $healthModel->campaignTriggerCheck($output, $verbose);
        if (!$quiet) {
            $healthModel->reportIncidents($output);
        }
        if ($verbose) {
            $output->writeln(
                '<info>'.$translator->trans(
                    'mautic.health.complete'
                ).'</info>'
            );
        }
        $this->completeRun();

        return 0;
    }
}
