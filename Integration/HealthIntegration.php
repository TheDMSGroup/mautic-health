<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticHealthBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

/**
 * Class HealthIntegration.
 */
class HealthIntegration extends AbstractIntegration
{

    /** @var string */
    const INCIDENT_IMPACT = 'minor';

    /**
     * @param string $status Blank for default status or:
     *                       degraded_performance|partial_outage|major_outage|under_maintenance
     * @param string $body
     */
    public function setComponentStatus($status = '', $body, $name)
    {
        if ($this->isConfigured()) {
            $featureSettings = $this->getIntegrationSettings()->getFeatureSettings();
            if (!empty($featureSettings['statuspage_component_id'])) {
                $components = $this->getComponents();
                foreach ($components as $component) {
                    if ($component['id'] === $featureSettings['statuspage_component_id']) {
                        // @todo - Update the component status.

                        // $clientIdKey = $this->getClientIdKey();
                        // $clientSKey  = $this->getClientSecretKey();
                        // $state       = $this->getAuthLoginState();
                        // $url         = $this->getAuthenticationUrl()
                        //     .'pages/'.$this->keys[$clientIdKey].'/components.json'
                        //     .'?api_key='.$this->keys[$clientSKey]
                        //     .'&response_type=code'
                        //     .'&state='.$state;
                        //
                        // $result = $this->makeRequest($this->getAccessTokenUrl(), ['ignore_event_dispatch' => true], 'PATCH');

                        // @todo - Close/create/update related incident.
                        // $incidents = $this->getIncidents(null, false);
                        $incidents = $this->getIncidents($featureSettings['statuspage_component_id']);
                        if (count($incidents)) {
                            foreach ($incidents as $incident) {
                                $change = false;
                                if ($incident['status'] !== $status) {
                                    $change = true;
                                }
                                if (!empty($incident['incident_updates'])) {
                                    $lastUpdate = end($incident['incident_updates']);
                                    if ($lastUpdate['body'] !== $body) {
                                        $change = true;
                                    }
                                }
                                if ($change) {
                                    // Update/Close the incident.
                                    $componentIds   = [];
                                    $componentIds[] = $component['id'];
                                    if (!empty($lastUpdate)) {
                                        foreach ($lastUpdate['affected_components'] as $affectedComponent) {
                                            if (!empty($affectedComponent['id'])) {
                                                $componentIds[] = $affectedComponent['id'];
                                            }
                                        }
                                    }
                                    $componentIds = array_unique($componentIds);
                                    $this->updateIncident($incident['id'], $componentIds, $body, $status, $name);
                                }
                            }
                        } else {
                            // @todo - Create an incident.
                        }
                        break;
                    }
                }
            }
        }
        $tmp = 1;
    }

    /**
     * Get the list of statuspage components to possibly update.
     *
     * @return array
     */
    private function getComponents()
    {
        $components = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheName   = 'statuspageComponents'.$this->keys[$clientIdKey];
            $cacheExpire = 300;
            if (!$components = $this->cache->get($cacheName, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl()
                    .'pages/'.$this->keys[$clientIdKey].'/components.json'
                    .'?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                $components = $this->makeRequest($url, ['ignore_event_dispatch' => true]);
                if (is_array($components) && count($components)) {
                    $this->cache->set($cacheName, $components, $cacheExpire);
                }
            }
        }

        return $components;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthenticationUrl()
    {
        return 'https://api.statuspage.io/v1/';
    }

    /**
     * Get the list of statuspage active incidents.
     *
     * @param null $componentId
     * @param bool $unresolvedOnly
     *
     * @return array|bool|mixed|string
     */
    private function getIncidents($componentId = null, $unresolvedOnly = true)
    {
        $incidents = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheName   = 'statuspageIncidents'.$this->keys[$clientIdKey];
            $cacheExpire = 10;
            if (!$incidents = $this->cache->get($cacheName, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl();
                if ($unresolvedOnly) {
                    $url .= 'pages/'.$this->keys[$clientIdKey].'/incidents/unresolved.json';
                } else {
                    $url .= 'pages/'.$this->keys[$clientIdKey].'/incidents.json';
                }
                $url       .= '?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                $incidents = $this->makeRequest($url, ['ignore_event_dispatch' => true]);
                if (is_array($incidents) && count($incidents)) {
                    $this->cache->set($cacheName, $incidents, $cacheExpire);
                }
            }
        }
        // Narrow down to just the affected component if specified.
        if ($incidents && $componentId) {
            $affected = [];
            foreach ($incidents as $incident) {
                if (!empty($incident['incident_updates'])) {
                    foreach ($incident['incident_updates'] as $update) {
                        if (!empty($update['affected_components'])) {
                            foreach ($update['affected_components'] as $component) {
                                if (!empty($component['code']) && $component['code'] === $componentId) {
                                    $affected[] = $incident;
                                    continue;
                                }
                            }
                        }
                    }
                }
            }
            $incidents = $affected;
        }

        return $incidents;
    }

    /**
     * Update an active statuspage incident.
     *
     * @param       $incidentId
     * @param array $componentIds List of components affected by the incident
     * @param null  $body         The body of the new incident update that will be created
     * @param null  $status       The status, one of investigating|identified|monitoring|resolved
     * @param null  $name         The name of the incident
     *
     * @return array|mixed|string
     */
    private function updateIncident($incidentId, $componentIds, $body = null, $status = null, $name = null)
    {
        $result = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheName   = 'statuspageUpdateIncident'.implode(
                    '-',
                    [$this->keys[$clientIdKey], $componentIds, $body, $status, $name]
                );
            $cacheExpire = 10;
            if (!$result = $this->cache->get($cacheName, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl()
                    .'pages/'.$this->keys[$clientIdKey].'/incidents/'.$incidentId.'.json'
                    .'?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                if ($body) {
                    $url .= '&incident[body]='.urlencode($body);
                }
                if ($status) {
                    $url .= '&incident[status]='.urlencode($status);
                }
                if ($name) {
                    $url .= '&incident[name]='.urlencode($name);
                }
                foreach ($componentIds as $componentId) {
                    $url .= '&incident[component_ids][]='.(int) $componentId;
                }
                $result = $this->makeRequest($url, ['ignore_event_dispatch' => true], 'PATCH');
                if (is_array($result) && count($result)) {
                    $this->cache->set($cacheName, $result, $cacheExpire);
                }
            }
        }

        return $result;
    }

    /**
     * @return string
     */
    public function getAccessTokenUrl()
    {
        return 'https://api.statuspage.io/v1/?api_key='.$this->keys[$this->getClientSecretKey()];
    }

    /**
     * Note: Statuspage doesn't yet support a bounce-back redirect as far as I can discern :(
     *
     * @return string
     */
    public function getAuthLoginUrl()
    {
        $callback    = $this->getAuthCallbackUrl();
        $clientIdKey = $this->getClientIdKey();
        $clientSKey  = $this->getClientSecretKey();
        $state       = $this->getAuthLoginState();
        $url         = $this->getAuthenticationUrl()
            .'pages/'.$this->keys[$clientIdKey].'.json'
            .'?api_key='.$this->keys[$clientSKey]
            .'&response_type=code'
            .'&redirect_uri='.urlencode($callback)
            .'&state='.$state;

        if ($this->session) {
            $this->session->set($this->getName().'_csrf_token', $state);
        }

        return $url;
    }

    /**
     * @return string
     */
    public function getAuthCallbackUrl()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Health';
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthenticationType()
    {
        return 'oauth2';
    }

    /**
     * {@inheritdoc}
     */
    public function getRequiredKeyFields()
    {
        return [
            'client_id'     => 'mautic.health.statuspage_id',
            'client_secret' => 'mautic.health.statuspage_secret',
        ];
    }

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array                                        $data
     * @param string                                       $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ($formArea == 'features') {
            $builder->add(
                'campaign_rebuild_threshold',
                'number',
                [
                    'label' => $this->translator->trans('mautic.health.campaign_rebuild_threshold'),
                    'data'  => !isset($data['campaign_rebuild_threshold']) ? 10000 : $data['campaign_rebuild_threshold'],
                    'attr'  => [
                        'tooltip' => $this->translator->trans('mautic.health.campaign_rebuild_threshold.tooltip'),
                    ],
                ]
            );
            $builder->add(
                'campaign_trigger_threshold',
                'number',
                [
                    'label' => $this->translator->trans('mautic.health.campaign_trigger_threshold'),
                    'data'  => !isset($data['campaign_trigger_threshold']) ? 1000 : $data['campaign_trigger_threshold'],
                    'attr'  => [
                        'tooltip' => $this->translator->trans('mautic.health.campaign_trigger_threshold.tooltip'),
                    ],
                ]
            );
            $choices = [];
            foreach ($this->getComponents() as $component) {
                $choices[$component['id']] = $component['name'];
            };
            $builder->add(
                'statuspage_component_id',
                'choice',
                [
                    'label'      => $this->translator->trans('mautic.health.statuspage_component_id'),
                    'multiple'   => false,
                    'choices'    => $choices,
                    'required'   => false,
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => [
                        'class'   => 'form-control',
                        'tooltip' => $this->translator->trans('mautic.health.statuspage_component_id.tooltip'),
                    ],
                ]
            );
            $builder->add(
                'statuspage_component_incidents',
                'yesno_button_group',
                [
                    'label' => $this->translator->trans('mautic.health.statuspage_component_incidents'),
                    'data'  => !isset($data['statuspage_component_incidents']) ? false : (bool) $data['statuspage_component_incidents'],
                    'attr'  => [
                        'tooltip' => $this->translator->trans('mautic.health.statuspage_component_incidents.tooltip'),
                    ],
                ]
            );
        }
    }

    /**
     * @return array
     */
    public function getSupportedFeatures()
    {
        return [];
    }

    /**
     * Update an active statuspage incident.
     *
     * @param       $incidentId
     * @param array $componentIds List of components affected by the incident
     * @param null  $body         The body of the new incident update that will be created
     * @param null  $status       The status, one of investigating|identified|monitoring|resolved
     * @param null  $name         The name of the incident
     *
     * @return array|mixed|string
     */
    private function createIncident($incidentId, $componentIds, $body = null, $status = null, $name = null)
    {
        $result = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheName   = 'statuspageUpdateIncident'.$this->keys[$clientIdKey];
            $cacheExpire = 10;
            if (!$result = $this->cache->get($cacheName, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl()
                    .'pages/'.$this->keys[$clientIdKey].'/incidents/'.$incidentId.'.json'
                    .'?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                if ($body) {
                    $url .= '&incident[body]='.urlencode($body);
                }
                if ($status) {
                    $url .= '&incident[status]='.urlencode($status);
                }
                if ($name) {
                    $url .= '&incident[name]='.urlencode($name);
                }
                foreach ($componentIds as $componentId) {
                    $url .= '&incident[component_ids][]='.(int) $componentId;
                }
                $result = $this->makeRequest($url, ['ignore_event_dispatch' => true], 'PATCH');
                if (is_array($result) && count($result)) {
                    $this->cache->set($cacheName, $result, $cacheExpire);
                }
            }
        }

        return $result;
    }
}
