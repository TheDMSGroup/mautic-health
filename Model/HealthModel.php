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
use Mautic\CampaignBundle\Event\CampaignEvent;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\CoreBundle\Helper\CacheStorageHelper;
use Mautic\CoreBundle\Helper\ProgressBarHelper;
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
    protected $delays = [];

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

    /** @var CacheStorageHelper */
    protected $cache;

    /** @var array */
    protected $publishedCampaignsWithEvents = [];

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
     */
    public function campaignKickoffCheck(OutputInterface $output = null)
    {
        $campaignIds = array_keys($this->getPublishedCampaignsWithEvents());
        if (!$campaignIds) {
            return;
        }
        $limit = !empty($this->settings['campaign_kickoff_delay']) ? (int) $this->settings['campaign_kickoff_delay'] : 3600;
        $query = $this->slaveQueryBuilder();
        $query->select(
            'cl.campaign_id AS campaign_id, count(cl.lead_id) AS contact_count, ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(cl.date_added))) as avg_delay_s'
        );
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads cl USE INDEX(campaign_leads_date_added)');
        $query->where('cl.date_added > DATE_ADD(NOW(), INTERVAL -2 DAY)');
        $query->andWhere('cl.campaign_id IN ('.implode(',', $campaignIds).')');
        // Adding the manually removed check causes an index miss in 2.15.0+
        // $query->andWhere('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
        $query->andWhere(
            'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e USE INDEX(campaign_leads) WHERE cl.lead_id = e.lead_id AND e.campaign_id = cl.campaign_id AND e.rotation = cl.rotation)'
        );
        $query->groupBy('cl.campaign_id');
        $query->orderBy('avg_delay_s', 'DESC');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id             = $campaign['campaign_id'];
            $delay          = [
                'campaign_id'   => $id,
                'campaign_name' => $this->getPublishedCampaigns($id),
                'event_id'      => null,
                'event_name'    => null,
                'type'          => 'kickoff',
                'contact_count' => $campaign['contact_count'],
                'avg_delay_s'   => $campaign['avg_delay_s'],
                'body'          => 'Campaign '.$this->getPublishedCampaigns($id).
                    ' ('.$id.') has '.$campaign['contact_count'].
                    ' contacts (not realtime) awaiting kickoff with an average of '.
                    $campaign['avg_delay_s'].'s delay.',
            ];
            $this->delays[] = $delay;
            $this->output($delay, $limit, $output);
        }
    }

    /**
     * @param null $campaignId
     *
     * @return array|bool
     */
    private function getPublishedCampaignsWithEvents($campaignId = null)
    {
        if (!$this->publishedCampaignsWithEvents) {
            $campaignIds = array_keys($this->getPublishedCampaigns());
            if ($campaignIds) {
                /** @var CampaignEvent $event */
                foreach ($this->eventModel->getRepository()->getEntities(
                    [
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'IDENTITY(e.campaign)',
                                    'expr'   => 'in',
                                    'value'  => $campaignIds,
                                ],
                            ],
                        ],
                    ]
                ) as $event) {
                    $this->publishedCampaignsWithEvents[$event->getCampaign()->getId()] = $event->getCampaign()->getName();
                }
            }
        }
        if ($campaignId) {
            return isset($this->publishedCampaignsWithEvents[$campaignId]) ? $this->publishedCampaignsWithEvents[$campaignId] : null;
        } else {
            return $this->publishedCampaigns;
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
                $this->publishedCampaigns[$campaign['id']] = $campaign['name'];
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
    //         if (!isset($this->delays[$id])) {
    //             $this->delays[$id] = [];
    //         }
    //         $this->delays[$id]['rebuilds'] = $campaign['contact_count'];
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
     * @param $delay
     * @param $limit
     * @param $output
     */
    private function output($delay, $limit, $output)
    {
        if ($delay['avg_delay_s'] > $limit) {
            $status            = 'error';
            $this->incidents[] = $delay;
        } else {
            $status = 'info';
        }
        $progress = ProgressBarHelper::init($output, $limit);
        $progress->start();
        $progress->advance($delay['avg_delay_s']);
        $output->write(
            '  <'.$status.'>'.$delay['body'].'</'.$status.'>'
        );
        $output->writeln('');
    }

    /**
     * Get last delays from the cache.
     */
    public function getCache()
    {
        if (!$this->cache) {
            $this->cache = new CacheStorageHelper(
                CacheStorageHelper::ADAPTOR_DATABASE,
                'Health',
                $this->em->getConnection()
            );
        }

        return [
            'delays'     => $this->cache->get('delays'),
            'lastCached' => $this->cache->get('lastCached'),
        ];
    }

    /**
     * Store current delays in the cache and purge.
     */
    public function setCache()
    {
        if (!$this->cache) {
            $this->cache = new CacheStorageHelper(
                CacheStorageHelper::ADAPTOR_DATABASE,
                'Health',
                $this->em->getConnection()
            );
        }
        usort(
            $this->delays,
            function ($a, $b) {
                return $b['avg_delay_s'] - $a['avg_delay_s'];
            }
        );
        $this->cache->set('delays', $this->delays, null);
        $this->cache->set('lastCached', time(), null);
        $this->delays = [];
    }

    /**
     * @param OutputInterface|null $output
     */
    public function campaignScheduledCheck(OutputInterface $output = null)
    {
        $eventIds = array_keys($this->getPublishedEvents());
        if (!$eventIds) {
            return;
        }
        $limit = !empty($this->settings['campaign_scheduled_delay']) ? (int) $this->settings['campaign_scheduled_delay'] : 3600;
        $query = $this->slaveQueryBuilder();
        $query->select(
            'el.campaign_id, el.event_id, COUNT(el.lead_id) as contact_count, ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(el.trigger_date))) as avg_delay_s'
        );
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log el USE INDEX(campaign_events_scheduled)');
        $query->where('el.event_id IN ('.implode(',', $eventIds).')');
        $query->andWhere('el.is_scheduled = 1');
        $query->andWhere('el.trigger_date <= NOW()');
        $query->andWhere('el.trigger_date > DATE_ADD(NOW(), INTERVAL -2 DAY)');
        $query->groupBy('el.event_id');
        $query->orderBy('avg_delay_s', 'DESC');
        $events = $query->execute()->fetchAll();
        foreach ($events as $event) {
            $id             = $event['campaign_id'];
            $delay          = [
                'campaign_id'   => $id,
                'campaign_name' => $this->getPublishedCampaigns($id),
                'event_id'      => $event['event_id'],
                'event_name'    => $this->getPublishedEvents($event['event_id']),
                'type'          => 'scheduled',
                'contact_count' => $event['contact_count'],
                'avg_delay_s'   => $event['avg_delay_s'],
                'body'          => 'Campaign '.$this->getPublishedCampaigns($id).
                    ' ('.$id.') has '.$event['contact_count'].
                    ' contacts queued for scheduled event '.$this->getPublishedEvents(
                        $event['event_id']
                    ).' ('.$event['event_id'].') with an average of '.
                    $event['avg_delay_s'].'s delay.',
            ];
            $this->delays[] = $delay;
            $this->output($delay, $limit, $output);
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
                        'filter' => [
                            'force' => [
                                [
                                    'column' => 'IDENTITY(e.campaign)',
                                    'expr'   => 'in',
                                    'value'  => $campaignIds,
                                ],
                            ],
                        ],
                    ]
                ) as $event) {
                    $this->publishedEvents[$event->getId()] = $event->getName();
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
            foreach ($this->incidents as $incident) {
                if (!empty($incident['body'])) {
                    $body[] = $incident['body'];
                }
            }
            $body = implode(' ', $body);
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
