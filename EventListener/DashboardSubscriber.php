<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\EventListener;

use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\EventListener\DashboardSubscriber as MainDashboardSubscriber;
use MauticPlugin\MauticHealthBundle\Model\HealthModel;

/**
 * Class DashboardSubscriber.
 */
class DashboardSubscriber extends MainDashboardSubscriber
{
    /**
     * Define the name of the bundle/category of the widget(s).
     *
     * @var string
     */
    protected $bundle = 'campaign';

    /**
     * Define the widget(s).
     *
     * @var string
     */
    protected $types = [
        'campaign.health' => [],
    ];

    /**
     * @var HealthModel
     */
    protected $healthModel;

    /**
     * DashboardSubscriber constructor.
     *
     * @param HealthModel $healthModel
     */
    public function __construct(HealthModel $healthModel)
    {
        $this->healthModel = $healthModel;
    }

    /**
     * Set a widget detail when needed.
     *
     * @param WidgetDetailEvent $event
     */
    public function onWidgetDetailGenerate(WidgetDetailEvent $event)
    {
        // This always pulls from cached data from the cron task.
        // if (!$event->isCached()) {
        // }
        $cache  = $this->healthModel->getCache();
        $widget = $event->getWidget();
        if ($widget->getHeight() < 330) {
            $widget->setHeight(330);
        }
        $params             = $widget->getParams();
        $data               = [];
        $data['params']     = $params;
        $data['height']     = $widget->getHeight();
        $data['delays']     = isset($cache['delays']) ? $cache['delays'] : [];
        $data['lastCached'] = isset($cache['lastCached']) ? $cache['lastCached'] : null;
        $event->setTemplateData(['data' => $data]);

        if ('campaign.health' == $event->getType()) {
            $event->setTemplate('MauticHealthBundle:Widgets:health.html.php');
        }

        $event->stopPropagation();
    }
}
