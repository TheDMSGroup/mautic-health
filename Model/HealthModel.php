<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\Model;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class HealthModel.
 */
class HealthModel
{
    /** @var EntityManager */
    protected $em;

    /** @var array */
    protected $campaigns;

    /** @var int */
    protected $campaignRebuildThreshold;

    /** @var int */
    protected $campaignTriggerThreshold;

    /** @var array */
    protected $incidents;

    /**
     * Health constructor.
     *
     * @param EntityManager $em
     */
    public function __construct(
        EntityManager $em
    ) {
        $this->em = $em;
    }

    /**
     * @param $campaignRebuildThreshold
     *
     * @return $this
     */
    public function setCampaignRebuildThreshold($campaignRebuildThreshold)
    {
        $this->campaignRebuildThreshold = $campaignRebuildThreshold;

        return $this;
    }

    /**
     * @param $campaignTriggerThreshold
     *
     * @return $this
     */
    public function setCampaignTriggerThreshold($campaignTriggerThreshold)
    {
        $this->campaignTriggerThreshold = $campaignTriggerThreshold;

        return $this;
    }

    /**
     * Discern the number of leads waiting on mautic:campaign:rebuild.
     * This typically means a large segment has been given a campaign.
     *
     * @param OutputInterface $output
     * @param bool            $verbose
     */
    public function campaignRebuildCheck(OutputInterface $output = null, $verbose = false)
    {
        $query = $this->em->getConnection()->createQueryBuilder();
        $query->select('cl.campaign_id as campaign_id, count(DISTINCT(cl.lead_id)) as contact_count');
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl');
        $query->where('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
        $query->andWhere(
            'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e WHERE (cl.lead_id = e.lead_id) AND (e.campaign_id = cl.campaign_id))'
        );
        $query->groupBy('cl.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['rebuilds'] = $campaign['contact_count'];
            if ($output) {
                if (!isset($this->incidents[$id])) {
                    $this->incidents[$id] = [];
                }
                if ($campaign['contact_count'] > $this->campaignRebuildThreshold) {
                    $this->incidents[$id]['rebuilds'] = $campaign['contact_count'];
                    $status                           = 'error';
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued to enter the campaign from a segment.'
                    .'</'.$status.'>'
                );
            }
        }
    }

    /**
     * Discern the number of leads waiting on mautic:campaign:trigger.
     * This will happen if it takes longer to execute triggers than for new contacts to be consumed.
     *
     * @param OutputInterface $output
     * @param bool            $verbose
     */
    public function campaignTriggerCheck(OutputInterface $output = null, $verbose = false)
    {
        $query = $this->em->getConnection()->createQueryBuilder();
        $query->select('el.campaign_id as campaign_id, COUNT(DISTINCT(el.lead_id)) as contact_count');
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'el');
        $query->where('el.is_scheduled = 1');
        $query->andWhere('el.trigger_date <= NOW()');
        $query->groupBy('el.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['triggers'] = $campaign['contact_count'];
            if ($output) {
                if (!isset($this->incidents[$id])) {
                    $this->incidents[$id] = [];
                }
                if ($campaign['contact_count'] > $this->campaignTriggerThreshold) {
                    $this->incidents[$id]['triggers'] = $campaign['contact_count'];
                    $status                           = 'error';
                } else {
                    $status = 'info';
                    if (!$verbose) {
                        continue;
                    }
                }
                $output->writeln(
                    '<'.$status.'>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued for events to be triggered.'
                    .'</'.$status.'>'
                );
            }
        }
    }

    /**
     * Gets all current incidents where we are over the limit.
     */
    public function getIncidents()
    {
        return $this->incidents;
    }
}
