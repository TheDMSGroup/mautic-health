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
    protected $campaignRebuildThreshold = 10;

    /** @var int */
    protected $campaignTriggerThreshold = 10;

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
     * Discern the number of leads waiting on mautic:campaign:rebuild.
     * This typically means a large segment has been given a campaign.
     *
     * @param null $output
     */
    public function campaignRebuildCheck($output = null)
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
                $output->writeln(
                    '<info>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued to enter the campaign from a segment.'
                    .'</info>'
                );
                if (!isset($this->incidents[$id])) {
                    $this->incidents[$id] = [];
                }
                if ($campaign['leads_entering_campaign'] > $this->campaignRebuildThreshold) {
                    $this->incidents[$id]['rebuilds'] = $campaign['contact_count'];
                }
            }
        }
    }

    /**
     * Discern the number of leads waiting on mautic:campaign:trigger.
     * This will happen if it takes longer to execute triggers than for new contacts to be consumed.
     *
     * @param null $output
     */
    public function campaignTriggerCheck($output = null)
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
                $output->writeln(
                    '<info>'.
                    'Campaign '.$id.' has '.$campaign['contact_count'].' leads queued for events to be triggered.'
                    .'</info>'
                );
                if (!isset($this->incidents[$id])) {
                    $this->incidents[$id] = [];
                }
                if ($campaign['leads_entering_campaign'] > $this->campaignTriggerThreshold) {
                    $this->incidents[$id]['triggers'] = $campaign['contact_count'];
                }
            }
        }
    }

    /**
     * @param null $output
     *
     * @return array
     */
    public function getIncidents($output = null)
    {
        if ($output) {
            if ($this->incidents) {
                foreach ($this->incidents as $id => $incidents) {
                    foreach ($incidents as $type => $contact_count) {
                        $output->writeln(
                            '<error>'.
                            'Campaign '.$id.' is behind on '.$type.' by '.$contact_count.' contacts.'
                            .'</error>'
                        );
                    }
                }
            }
        }

        return $this->incidents;
    }
}
