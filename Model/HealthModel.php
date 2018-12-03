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

use Doctrine\ORM\EntityManager;
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
    protected $campaigns;

    /** @var array */
    protected $incidents;

    /** @var IntegrationHelper */
    protected $integrationHelper;

    /** @var array */
    protected $settings;

    /** @var HealthIntegration */
    protected $integration;

    /**
     * HealthModel constructor.
     *
     * @param EntityManager     $em
     * @param IntegrationHelper $integrationHelper
     */
    public function __construct(
        EntityManager $em,
        IntegrationHelper $integrationHelper
    ) {
        $this->em                = $em;
        $this->integrationHelper = $integrationHelper;

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

    public function avgDelayCheck(OutputInterface $output = null, $verbose = false) {
        $prefix = MAUTIC_TABLE_PREFIX;
        $sql = <<<EOSQL
-- All mautic delays merged. Two queries (the first is important).
SET @@group_concat_max_len = 10000000000000;
SELECT *
FROM (
	SELECT NULL as campaign_id,
		NULL as campaign_name,
		NULL as event_id,
		NULL as event_name,
		NULL as lead_count,
		NULL as type,
		NULL as avg_delay_s 
	FROM (
		SELECT @campaigns := (
			SELECT GROUP_CONCAT(c.id SEPARATOR ',')
			FROM {$prefix}campaigns c
			WHERE c.is_published = 1
		)
		UNION ALL
		SELECT @events := (
			SELECT GROUP_CONCAT(ce.id SEPARATOR ',')
			FROM {$prefix}campaign_events ce
			WHERE ce.is_published = 1
				AND FIND_IN_SET(ce.campaign_id, @campaigns) > 0
		)
	) vars
UNION ALL
	SELECT
		cl.campaign_id AS campaign_id,
		c.name as campaign_name,
		NULL as event_id,
		NULL as event_name,
		count(cl.lead_id) AS lead_count,
		'kickoff' as 'type',
		ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(cl.date_added))) as avg_delay_s
	FROM {$prefix}campaign_leads cl
	LEFT JOIN {$prefix}campaigns c
		ON c.id = cl.campaign_id
	WHERE (NOT EXISTS (
		SELECT null FROM {$prefix}campaign_lead_event_log e
		WHERE
			cl.lead_id = e.lead_id
			AND e.campaign_id = cl.campaign_id
		))
	AND cl.date_added > DATE_ADD(NOW(), INTERVAL -1 HOUR)
	AND FIND_IN_SET(cl.campaign_id, @campaigns) > 0
	GROUP BY cl.campaign_id
UNION ALL
	SELECT
		el.campaign_id,
		c.name as campaign_name,
		el.event_id,
		ce.name as event_name,
		COUNT(el.lead_id) as lead_count,
		'scheduled' as 'type',
		ROUND(AVG(UNIX_TIMESTAMP() - UNIX_TIMESTAMP(el.trigger_date))) as avg_delay_s
	FROM {$prefix}campaign_lead_event_log el
	LEFT JOIN {$prefix}campaigns c
		ON c.id = el.campaign_id
	LEFT JOIN {$prefix}campaign_events ce
		ON ce.id = el.event_id
	WHERE
		el.is_scheduled = 1
		AND el.trigger_date <= NOW()
		AND FIND_IN_SET(el.event_id, @events) > 0
	GROUP BY el.event_id
) combined
WHERE avg_delay_s > 0
ORDER BY avg_delay_s DESC;
EOSQL;

        $query = $em->getConnection()->prepare($sql);
        $query->execute();
        $results = $query->fetchAll();

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
        $threshold = !empty($this->settings['campaign_rebuild_threshold']) ? (int) $this->settings['campaign_rebuild_threshold'] : 10000;
        $query     = $this->em->getConnection()->createQueryBuilder();
        $query->select(
            'cl.campaign_id as campaign_id, c.name as campaign_name, count(DISTINCT(cl.lead_id)) as contact_count'
        );
        $query->leftJoin('cl', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = cl.campaign_id');
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_leads', 'cl');
        $query->where('cl.manually_removed IS NOT NULL AND cl.manually_removed = 0');
        $query->andWhere(
            'NOT EXISTS (SELECT null FROM '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log e WHERE (cl.lead_id = e.lead_id) AND (e.campaign_id = cl.campaign_id))'
        );
        $query->andWhere('c.is_published = 1');
        $query->groupBy('cl.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['rebuilds'] = $campaign['contact_count'];
            if ($output) {
                $body = 'Campaign '.$campaign['campaign_name'].' ('.$id.') has '.$campaign['contact_count'].' ('.$threshold.') leads queued to enter the campaign from a segment.';
                if ($campaign['contact_count'] > $threshold) {
                    $status                           = 'error';
                    $this->incidents[$id]['rebuilds'] = [
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
     * Discern the number of leads waiting on mautic:campaign:trigger.
     * This will happen if it takes longer to execute triggers than for new contacts to be consumed.
     *
     * @param OutputInterface $output
     * @param bool            $verbose
     */
    public function campaignTriggerCheck(OutputInterface $output = null, $verbose = false)
    {
        $threshold = !empty($this->settings['campaign_trigger_threshold']) ? (int) $this->settings['campaign_trigger_threshold'] : 1000;
        $query     = $this->em->getConnection()->createQueryBuilder();
        $query->select(
            'el.campaign_id as campaign_id, c.name as campaign_name, COUNT(DISTINCT(el.lead_id)) as contact_count'
        );
        $query->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'el');
        $query->leftJoin('el', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = el.campaign_id');
        $query->leftJoin('el', MAUTIC_TABLE_PREFIX.'campaign_events', 'e', 'e.id = el.event_id');
        $query->where('el.is_scheduled = 1');
        $query->andWhere('el.trigger_date <= NOW()');
        $query->andWhere('e.is_published = 1');
        $query->andWhere('c.is_published = 1');
        $query->groupBy('el.campaign_id');
        $campaigns = $query->execute()->fetchAll();
        foreach ($campaigns as $campaign) {
            $id = $campaign['campaign_id'];
            if (!isset($this->campaigns[$id])) {
                $this->campaigns[$id] = [];
            }
            $this->campaigns[$id]['triggers'] = $campaign['contact_count'];
            if ($output) {
                $body = 'Campaign '.$campaign['campaign_name'].' ('.$id.') has '.$campaign['contact_count'].' ('.$threshold.') leads queued for events to be triggered.';
                if ($campaign['contact_count'] > $threshold) {
                    $status                           = 'error';
                    $this->incidents[$id]['triggers'] = [
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
