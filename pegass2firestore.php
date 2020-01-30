<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/PegassClient.php';
require __DIR__ . '/Volunteer.php';

use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;

if ('true' !== $_SERVER['X_APPENGINE_CRON']) {
    die('I am a cron job');
}

// Fetch all volunteers in pegass
$pegass = new PegassClient();
$pages = $pegass->getVolunteers('889'); // Paris 1/2

// Filter out everyone's emails from pegass result
$nivols = [];
foreach ($pages as $page) {
    foreach ($page['list'] as $volunteer) {
        $nivol = new Volunteer();
        $nivol->nivol = ltrim($volunteer['id'], '0`');
        $nivol->emails = $pegass->fetchEmails($volunteer['coordonnees']);
        $nivol->enabled = $volunteer['actif'];
        $nivols[$nivol->nivol] = $nivol;
    }
}

// Fetch all available volunteers in Firestore
$db = new FirestoreClient();
$ref = $db->collection('pegass');
$store = [];
$snapshot = $ref->documents();
foreach ($snapshot as $doc => $entity) {
//    /** @var DocumentSnapshot $entity */
//    if (!$entity->offsetExists('nivol')) {
//        $ref->document($entity->id())->delete();
//    }

    $store[$entity['nivol']] = $entity;
}

// Disable all volunteers from firestore that are missing in pegass
$missing = array_diff(array_keys($store), array_keys($nivols));
$missing = array_keys($store);
foreach ($missing as $nivol) {
    /** @var DocumentSnapshot $entity */
    $entity = $store[$nivol];
    $ref->document($entity->id())->update([
        ['path' => 'enabled', 'value' => false],
    ]);
}

// Create all volunteers that are missing in firestore
$new = array_diff(array_keys($nivols), array_keys($store));
foreach ($new as $nivol) {
    /** @var Volunteer $volunteer */
    $volunteer = $nivols[$nivol];
    $ref->newDocument()->set([
        'emails' => $volunteer->emails,
        'enabled' => $volunteer->enabled,
        'nivol' => $volunteer->nivol,
        'subscribed' => true,
        'valid_email_index' => 0,
    ]);
}

// Update all volunteers that are in firestore
foreach (array_intersect(array_keys($nivols), array_keys($store)) as $nivol) {
    /** @var Volunteer $volunteer */
    $volunteer = $nivols[$nivol];
    /** @var DocumentSnapshot $entity */
    $entity = $store[$nivol];

    $storeEmails = $entity['emails'];
    sort($storeEmails);
    $pegassEmails = $volunteer->emails;
    sort($pegassEmails);

    if ($volunteer->enabled !== $entity['enabled']
        || json_encode($pegassEmails) !== json_encode($storeEmails)) {
        $ref->document($entity->id())->update([
            ['path' => 'emails', 'value' => $volunteer->emails],
            ['path' => 'enabled', 'value' => $volunteer->enabled],
        ]);
    }
}

