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
    /**
     * @param null $incidentStatus
     * @param null $componentStatus
     * @param null $name
     * @param null $body
     */
    public function setComponentStatus($incidentStatus = null, $componentStatus = null, $name = null, $body = null)
    {
        if ($this->isConfigured()) {
            $featureSettings = $this->getIntegrationSettings()->getFeatureSettings();
            if (!empty($featureSettings['statuspage_component_id'])) {
                $components = $this->getComponents();
                foreach ($components as $component) {
                    if ($component['id'] === $featureSettings['statuspage_component_id']) {
                        // Update the component status if needed.
                        if ($componentStatus !== $component['status']) {
                            $clientIdKey = $this->getClientIdKey();
                            $clientSKey  = $this->getClientSecretKey();
                            $state       = $this->getAuthLoginState();
                            $url         = $this->getAuthenticationUrl()
                                .'pages/'.$this->keys[$clientIdKey].'/components/'.$component['id'].'.json'
                                .'?api_key='.$this->keys[$clientSKey]
                                .'&response_type=code'
                                .'&state='.$state
                                .'&component[status]='.urlencode($componentStatus);

                            $result = $this->makeRequest($url, ['ignore_event_dispatch' => true], 'PATCH');
                            if (!empty($result['error'])) {
                                return;
                            }
                        }
                        if (isset($featureSettings['statuspage_component_incidents']) && $featureSettings['statuspage_component_incidents']) {
                            $incidents      = $this->getIncidents($featureSettings['statuspage_component_id']);
                            $componentIds   = [];
                            $componentIds[] = $component['id'];
                            if (count($incidents)) {
                                foreach ($incidents as $incident) {
                                    $change = false;
                                    if ($incident['status'] !== $incidentStatus) {
                                        $change = true;
                                    }
                                    if (!empty($incident['incident_updates'])) {
                                        $lastUpdate = reset($incident['incident_updates']);
                                        if ($lastUpdate['body'] !== $body) {
                                            $change = true;
                                        }
                                    }
                                    if ($change) {
                                        // Update/Close the incident.
                                        if (!empty($lastUpdate)) {
                                            foreach ($lastUpdate['affected_components'] as $affectedComponent) {
                                                if (!empty($affectedComponent['id'])) {
                                                    $componentIds[] = $affectedComponent['id'];
                                                }
                                            }
                                        }
                                        $componentIds = array_unique($componentIds);
                                        $this->updateIncident(
                                            $incident['id'],
                                            $incidentStatus,
                                            $name,
                                            $body,
                                            $componentIds
                                        );
                                    }
                                }
                            } elseif ('resolved' !== $incidentStatus) {
                                $this->createIncident($incidentStatus, $name, $body, $componentIds);
                            }
                        }
                        break;
                    }
                }
            }
        }
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
            $cacheKey    = 'statuspageComponents'.$this->keys[$clientIdKey];
            $cacheExpire = 10;
            if (!$components = $this->cache->get($cacheKey, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl()
                    .'pages/'.$this->keys[$clientIdKey].'/components.json'
                    .'?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                $components = $this->makeRequest($url, ['ignore_event_dispatch' => true]);
                if (is_array($components) && count($components)) {
                    $this->cache->set($cacheKey, $components, $cacheExpire);
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
            $cacheKey    = 'statuspageIncidents'.$this->keys[$clientIdKey];
            $cacheExpire = 10;
            if (!$incidents = $this->cache->get($cacheKey, $cacheExpire)) {
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
                    $this->cache->set($cacheKey, $incidents, $cacheExpire);
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
     * @param null  $incidentStatus The status, one of investigating|identified|monitoring|resolved
     * @param null  $name           The name of the incident
     * @param null  $body           The body of the new incident update that will be created
     * @param array $componentIds   List of components affected by the incident
     *
     * @return array|mixed|string
     */
    private function updateIncident($incidentId, $incidentStatus = null, $name = null, $body = null, $componentIds = [])
    {
        $result = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheKey    = 'statuspageUpdateIncident'.$this->keys[$clientIdKey].implode('-', $componentIds);
            $cacheExpire = 10;
            if (!$result = $this->cache->get($cacheKey, $cacheExpire)) {
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
                if ($incidentStatus) {
                    $url .= '&incident[status]='.urlencode($incidentStatus);
                }
                if ($name) {
                    $url .= '&incident[name]='.urlencode($name);
                }
                foreach ($componentIds as $componentId) {
                    $url .= '&incident[component_ids][]='.(int) $componentId;
                }
                $result = $this->makeRequest($url, ['ignore_event_dispatch' => true], 'PATCH');
                if (is_array($result) && count($result)) {
                    $this->cache->set($cacheKey, $result, $cacheExpire);
                }
            }
        }

        return $result;
    }

    /**
     * Update an active statuspage incident.
     *
     * @param null  $incidentStatus The status, one of investigating|identified|monitoring|resolved
     * @param null  $name           The name of the incident
     * @param null  $body           The body of the new incident update that will be created
     * @param array $componentIds   List of components affected by the incident
     *
     * @return array|mixed|string
     */
    private function createIncident($incidentStatus = null, $name = null, $body = null, $componentIds)
    {
        $result = [];
        if ($this->isConfigured()) {
            $clientIdKey = $this->getClientIdKey();
            $cacheKey    = 'statuspageCreateIncident'.$this->keys[$clientIdKey].implode('-', $componentIds);
            $cacheExpire = 10;
            if (!$result = $this->cache->get($cacheKey, $cacheExpire)) {
                $clientSKey = $this->getClientSecretKey();
                $state      = $this->getAuthLoginState();
                $url        = $this->getAuthenticationUrl()
                    .'pages/'.$this->keys[$clientIdKey].'/incidents.json'
                    .'?api_key='.$this->keys[$clientSKey]
                    .'&response_type=code'
                    .'&state='.$state;
                if ($body) {
                    $url .= '&incident[body]='.urlencode($body);
                }
                if ($incidentStatus) {
                    $url .= '&incident[status]='.urlencode($incidentStatus);
                }
                if ($name) {
                    $url .= '&incident[name]='.urlencode($name);
                }
                foreach ($componentIds as $componentId) {
                    $url .= '&incident[component_ids][]='.$componentId;
                }
                $result = $this->makeRequest($url, ['ignore_event_dispatch' => true], 'POST');
                if (is_array($result) && count($result)) {
                    $this->cache->set($cacheKey, $result, $cacheExpire);
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
     * Note: Statuspage doesn't yet support a bounce-back redirect as far as I can discern :(.
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
        if ('features' == $formArea) {
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
            }
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
}
