<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticHealthBundle\Integration\HealthIntegration;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class HealthModel.
 */
class HealthModel
{
    /** @var EntityManager */
    protected $em;

    /** @var array */
    protected $campaigns = [];

    /** @var array */
    protected $incidents;

    /** @var IntegrationHelper */
    protected $integrationHelper;

    /** @var array */
    protected $settings;

    /** @var HealthIntegration */
    protected $integration;

    /** @var CampaignModel */
    protected $campaignModel;

    /** @var EventModel */
    protected $eventModel;

    /** @var array */
    protected $publishedCampaigns = [];

    /** @var array */
    protected $publishedEvents = [];

    /**
     * HealthModel constructor.
     *
     * @param EntityManager     $em
     * @param IntegrationHelper $integrationHelper
     * @param CampaignModel     $campaignModel
     */
    public function __construct(
        EntityManager $em,
        IntegrationHelper $integrationHelper,
        CampaignModel $campaignModel,
        EventModel $eventModel
    ) {
        $this->em                = $em;
        $this->integrationHelper = $integrationHelper;
        $this->campaignModel     = $campaignModel;
        $this->eventModel        = $eventModel;

        /** @var \Mautic\PluginBundle\Integration\AbstractIntegration $integration */
        $integration = $this->integrationHelper->getIntegrationObject('Health');
        if ($integration) {
            $this->integration = $integration;
            $this->settings    = $integration->getIntegrationSettings()->getFeatureSettings();
        }
    }

    /**
     * @param $settings
     */
    public function setSettings($settings)
    {
        $this->settings = array_merge($this->settings, $settings);
    }

    /**
     * @param OutputInterface|null $output
     * @param bool                 $verbose
     */
    public function campaignKickoffCheck(OutputInterface $output = null, $verbose = false)
    {
        $campaignIds = array_keys($this->getPublishedCampaigns());
        if (!$campaignIds) {
            return;
        }
        $delay = !empty($this->settings['campaign_kickoff_delay']) ? (int) $this->settings['campaign_kickoff_delay'] : 3600;
        $query = $this->slaveQueryBuilder();
        $query->select(
            'cl.campaign_id AS campaign_id, count(cl.lead_id) AS contact_count, ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(cl.date_added))) as avg_delay_s'
        );
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl');
        $query->where('cl.date_added > DATE_ADD(NOW(), INTERVAL -1 HOUR)');
        $query->andWhere('cl.campaign_id IN (:campaigns)');
        // Adding the manually removed check causes an index miss in 2.15.0+
        // $query->andWhere('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
        $query->andWhere(
            'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e WHERE cl.lead_id = e.lead_id AND e.campaign_id = cl.campaign_id)'
        );
        $query->setParameter(':campaigns', $campaignIds);
        $query->groupBy('cl.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['kickoff'] = $campaign['contact_count'];
            if ($output) {
                $body = 'Campaign '.$this->getPublishedCampaigns($id)['name'].
                    ' ('.$id.') has '.$campaign['contact_count'].
                    ' contacts (not realtime) awaiting kickoff with an average of '.
                    $campaign['avg_delay_s'].'s delay.';
                if ($campaign['avg_delay_s'] > $delay) {
                    $body .= ' (max is '.$delay.')';
                    $status                          = 'error';
                    $this->incidents[$id]['kickoff'] = [
                        'contact_count' => $campaign['contact_count'],
                        'body'          => $body,
                    ];
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.$body.'</'.$status.'>'
                );
            }
        }
    }

    /**
     * @param null $campaignId
     *
     * @return array|bool
     */
    private function getPublishedCampaigns($campaignId = null)
    {
        if (!$this->publishedCampaigns) {
            foreach ($this->campaignModel->getPublishedCampaigns(true) as $campaign) {
                $this->publishedCampaigns[$campaign['id']] = $campaign;
            }
        }

        if ($campaignId) {
            return isset($this->publishedCampaigns[$campaignId]) ? $this->publishedCampaigns[$campaignId] : null;
        } else {
            return $this->publishedCampaigns;
        }
    }

    /**
     * Create a DBAL QueryBuilder preferring a slave connection if available.
     *
     * @return QueryBuilder
     */
    private function slaveQueryBuilder()
    {
        /** @var Connection $connection */
        $connection = $this->em->getConnection();
        if ($connection instanceof MasterSlaveConnection) {
            // Prefer a slave connection if available.
            $connection->connect('slave');
        }

        return new QueryBuilder($connection);
    }

    // /**
    //  * Discern the number of leads waiting on mautic:campaign:rebuild.
    //  * This typically means a large segment has been given a campaign.
    //  *
    //  * @param OutputInterface $output
    //  * @param bool            $verbose
    //  */
    // public function campaignRebuildCheck(OutputInterface $output = null, $verbose = false)
    // {
    //     $delay     = !empty($this->settings['campaign_rebuild_delay']) ? (int) $this->settings['campaign_rebuild_delay'] : 10000;
    //     $query     = $this->slaveQueryBuilder();
    //     $query->select(
    //         'cl.campaign_id as campaign_id, c.name as campaign_name, count(DISTINCT(cl.lead_id)) as contact_count'
    //     );
    //     $query->leftJoin('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = cl.campaign_id');
    //     $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl');
    //     $query->where('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
    //     $query->andWhere(
    //         'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e WHERE (cl.lead_id = e.lead_id) AND (e.campaign_id = cl.campaign_id))'
    //     );
    //     $query->andWhere('c.is_published = 1');
    //     $query->groupBy('cl.campaign_id');
    //     $campaigns = $query->execute()->fetchAll();
    //     foreach ($campaigns as $campaign) {
    //         $id = $campaign['campaign_id'];
    //         if (!isset($this->campaigns[$id])) {
    //             $this->campaigns[$id] = [];
    //         }
    //         $this->campaigns[$id]['rebuilds'] = $campaign['contact_count'];
    //         if ($output) {
    //             $body = 'Campaign '.$campaign['campaign_name'].' ('.$id.') has '.$campaign['contact_count'].' ('.$delay.') leads queued to enter the campaign from a segment.';
    //             if ($campaign['contact_count'] > $delay) {
    //                 $status                           = 'error';
    //                 $this->incidents[$id]['rebuilds'] = [
    //                     'contact_count' => $campaign['contact_count'],
    //                     'body'          => $body,
    //                 ];
    //             } else {
    //                 $status = 'info';
    //                 if (!$verbose) {
    //                     continue;
    //                 }
    //             }
    //             $output->writeln(
    //                 '<'.$status.'>'.$body.'</'.$status.'>'
    //             );
    //         }
    //     }
    // }

    /**
     * @param OutputInterface|null $output
     * @param bool                 $verbose
     */
    public function campaignScheduledCheck(OutputInterface $output = null, $verbose = false)
    {
        $eventIds = array_keys($this->getPublishedEvents());
        if (!$eventIds) {
            return;
        }
        $delay = !empty($this->settings['campaign_scheduled_delay']) ? (int) $this->settings['campaign_scheduled_delay'] : 3600;
        $query = $this->slaveQueryBuilder();
        $query->select(
            'el.campaign_id, el.event_id, COUNT(el.lead_id) as contact_count, ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(el.trigger_date))) as avg_delay_s'
        );
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'el');
        $query->where('el.is_scheduled = 1');
        $query->andWhere('el.trigger_date <= NOW()');
        $query->andWhere('el.event_id IN (:eventIds)');
        $query->setParameter(':eventIds', $eventIds);
        $query->groupBy('el.event_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['kickoff'] = $campaign['contact_count'];
            if ($output) {
                $body = 'Campaign '.$this->getPublishedCampaigns($id)['name'].
                    ' ('.$id.') has '.$campaign['contact_count'].
                    ' contacts queued for scheduled events with an average of '.
                    $campaign['avg_delay_s'].'s delay.';
                if ($campaign['avg_delay_s'] > $delay) {
                    $body .= ' (max is '.$delay.')';
                    $status                          = 'error';
                    $this->incidents[$id]['kickoff'] = [
                        'contact_count' => $campaign['contact_count'],
                        'body'          => $body,
                    ];
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.$body.'</'.$status.'>'
                );
            }
        }
    }

    /**
     * @param null $eventId
     *
     * @return array|bool
     */
    private function getPublishedEvents($eventId = null)
    {
        if (!$this->publishedEvents) {
            $campaignIds = array_keys($this->getPublishedCampaigns());
            if ($campaignIds) {
                foreach ($this->eventModel->getRepository()->getEntities(
                    [
                        'filter'         => [
                            'force' => [
                                [
                                    'column' => 'IDENTITY(e.campaign)',
                                    'expr'   => 'in',
                                    'value'  => $campaignIds,
                                ],
                            ],
                        ],
                        'hydration_mode' => 'HYDRATE_ARRAY',
                    ]
                ) as $event) {
                    $this->publishedEvents[$event['id']] = $event;
                }
            }
        }
        if ($eventId) {
            return isset($this->publishedEvents[$eventId]) ? $this->publishedEvents[$eventId] : null;
        } else {
            return $this->publishedEvents;
        }
    }

    /**
     * Gets all current incidents where we are over the limit.
     */
    public function getIncidents()
    {
        return $this->incidents;
    }

    /**
     * If Statuspage is enabled and configured, report incidents.
     *
     * @param OutputInterface|null $output
     */
    public function reportIncidents(OutputInterface $output = null)
    {
        if (!$this->integration) {
            return;
        }
        if ($this->incidents && !empty($this->settings['statuspage_component_id'])) {
            $name = 'Processing Delays';
            $body = [];
            foreach ($this->incidents as $campaignId => $campaign) {
                foreach ($campaign as $incident) {
                    if (!empty($incident['body'])) {
                        $body[] = $incident['body'];
                    }
                }
            }
            $body = implode('\n', $body);
            if ($output && $body) {
                $output->writeln(
                    '<info>'.'Notifying Statuspage.io'.'</info>'
                );
            }
            $this->integration->setComponentStatus('monitoring', 'degraded_performance', $name, $body);
        } else {
            $this->integration->setComponentStatus('resolved', 'operational', null, 'Application is healthy');
        }
    }
}
