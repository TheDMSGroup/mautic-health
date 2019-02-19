<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
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
use Symfony\Component\Console\Output\NullOutput;
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
            // ->addOption(
            //     'campaign-rebuild-delay',
            //     null,
            //     InputOption::VALUE_OPTIONAL,
            //     'The maximum number of contacts waiting to be ingested into a campaign from a segment.'
            // )
            ->addOption(
                'campaign-kickoff-delay',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of seconds average allowed for kickoff events at the top of the campaign.'
            )
            ->addOption(
                'campaign-scheduled-delay',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of seconds average allowed for scheduled events (beyond the intended delays).'
            )
            ->addOption(
                'campaign-inactive-delay',
                null,
                InputOption::VALUE_OPTIONAL,
                'The maximum number of seconds average allowed for inactive events (decisions).'
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
        // $campaignRebuildDelay   = $input->getOption('campaign-rebuild-delay');
        $campaignKickoffDelay   = $input->getOption('campaign-kickoff-delay');
        $campaignScheduledDelay = $input->getOption('campaign-scheduled-delay');
        $campaignInactiveDelay  = $input->getOption('campaign-inactive-delay');
        $quiet                  = $input->getOption('quiet');
        $container              = $this->getContainer();
        $translator             = $container->get('translator');

        if ($quiet) {
            $output = new NullOutput();
        }
        if (!$this->checkRunStatus($input, $output)) {
            return 0;
        }

        /** @var HealthModel $healthModel */
        $healthModel = $container->get('mautic.health.model.health');
        $output->writeln('<info>'.$translator->trans('mautic.health.running').'</info>');
        $settings = [];
        // if ($campaignRebuildDelay) {
        //     $settings['campaign_rebuild_delay'] = $campaignRebuildDelay;
        // }
        if ($campaignKickoffDelay) {
            $settings['campaign_kickoff_delay'] = $campaignKickoffDelay;
        }
        if ($campaignScheduledDelay) {
            $settings['campaign_scheduled_delay'] = $campaignScheduledDelay;
        }
        if ($campaignInactiveDelay) {
            $settings['campaign_inactive_delay'] = $campaignInactiveDelay;
        }
        if ($settings) {
            $healthModel->setSettings($settings);
        }

        $output->writeln('<info>'.$translator->trans('mautic.health.kickoff').'</info>');
        $healthModel->campaignKickoffCheck($output);

        $output->writeln('<info>'.$translator->trans('mautic.health.scheduled').'</info>');
        $healthModel->campaignScheduledCheck($output);

        // @todo - Add negative action path check.
        // $healthModel->campaignRebuildCheck($output, $verbose);
        $healthModel->setCache();

        $test = $healthModel->getCache();
        if (!$quiet) {
            $healthModel->reportIncidents($output);
        }
        $output->writeln(
            '<info>'.$translator->trans(
                'mautic.health.complete'
            ).'</info>'
        );
        $this->completeRun();

        return 0;
    }
}
