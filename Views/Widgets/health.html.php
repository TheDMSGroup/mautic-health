<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

?>

<div class="chart-wrapper" style="height:<?php echo $data['height'] - 66; ?>px; overflow-y: auto;">
    <div class="pt-sd pr-md pb-md pl-md">
        <?php if (count($data['delays'])): ?>
            <div id="health-status-table">
                <div class="responsive-table">
                    <table id="health-status" class="table table-striped table-bordered" width="100%" data-height="<?php echo $data['height']; ?>">
                        <thead>
                        <th>Campaign</th>
                        <th>Event</th>
                        <th>Type</th>
                        <th>Contacts</th>
                        <th>Delay</th>
                        </thead>
                        <tbody>
                        <?php foreach ($data['delays'] as $delay): ?>
                            <tr>
                                <th>
                                    <a href="/s/campaigns/view/<?php echo $delay['campaign_id']; ?>"><?php echo $delay['campaign_name']; ?></a>
                                </th>
                                <th><?php echo $delay['event_name']; ?> (<?php echo $delay['event_id']; ?>)</th>
                                <th><?php echo $delay['type']; ?></th>
                                <th><?php echo $delay['contact_count']; ?></th>
                                <th><?php echo $delay['avg_delay_s']; ?>s</th>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if (!empty($data['lastCached'])) {
                echo '<small>Calculated: '.(new \DateTime('@'.$data['lastCached']))->format('c').'</small>';
            }
            ?>
        <?php else: ?>
            <h3>No delays detected</h3>
        <?php endif; ?>
    </div>
</div>

