<?php

$channel = 'company.5.timeline.order.10';

$ordersPattern = 'company.{companyId}.orders';
$ordersRegex = '/^' . preg_replace('/\{(.*?)\}/', '([^\.]+)', $ordersPattern) . '$/';
echo "orders regex: {$ordersRegex}\n";
echo 'orders match: ' . preg_match($ordersRegex, $channel) . "\n";

$timelinePattern = 'company.{companyId}.timeline.{apiType}.{entityId}';
$timelineRegex = '/^' . preg_replace('/\{(.*?)\}/', '([^\.]+)', $timelinePattern) . '$/';
echo "timeline regex: {$timelineRegex}\n";
echo 'timeline match: ' . preg_match($timelineRegex, $channel) . "\n";
