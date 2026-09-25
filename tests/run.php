<?php
/* Jeu d'essai, sans base de données :
 *
 *   php tests/run.php
 *
 * Il vérifie le déroulement des expressions cron, qui décide de tout ce que le
 * calendrier affiche, et la lecture des dates dans les commandes. Le calcul
 * s'appuie sur la bibliothèque cron du coeur : il faut une installation Jeedom
 * sur la machine (seul son dossier vendor est chargé). */

date_default_timezone_set('Europe/Brussels');
$autoload = '/var/www/html/vendor/autoload.php';
if (!is_readable($autoload)) {
    echo "Bibliothèque cron du coeur introuvable ($autoload) : essai ignoré.\n";
    exit(2);
}
require_once $autoload;
require_once __DIR__ . '/../core/class/calendrierbeCron.class.php';
require_once __DIR__ . '/../core/class/calendrierbeCollecte.class.php';

$echecs = 0;
function verifie($_titre, $_obtenu, $_attendu) {
    global $echecs;
    if ($_obtenu === $_attendu) {
        echo "ok      $_titre\n";
        return;
    }
    $echecs++;
    echo "ÉCHEC   $_titre\n        obtenu : " . var_export($_obtenu, true) . "\n        attendu : " . var_export($_attendu, true) . "\n";
}
function dates($_list) {
    return ($_list === null) ? null : array_map(function ($ts) { return date('Y-m-d H:i', $ts); }, $_list);
}
function ts($_text) {
    return strtotime($_text);
}

/* L'oracle : cronIsDue() du coeur (core/php/utils.inc.php), rejoué minute par
 * minute comme jeeCron le fait, avec son blocage du retour à l'heure d'hiver. */
function coeur($_cron, $_from, $_to) {
    $out = array();
    for ($t = $_from - $_from % 60; $t <= $_to; $t += 60) {
        if ($t < $_from) {
            continue;
        }
        $jour = new DateTime('@' . $t);
        $jour->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $minuit = (clone $jour)->modify('today midnight');
        $demain = (clone $jour)->modify('today midnight +1 day');
        if (($demain->format('I') - $minuit->format('I')) == -1 && date('I', $t) == 1 && date('Gi', $t) > 159) {
            continue;
        }
        $schedule = explode(' ', trim($_cron));
        if (count($schedule) == 6 && $schedule[5] != '*' && $schedule[5] != date('Y', $t)) {
            continue;
        }
        try {
            $c = new Cron\CronExpression(calendrierbeCron::fix($_cron), new Cron\FieldFactory);
            if ($c->isDue(date('Y-m-d H:i:s', $t))) {
                $out[] = $t;
            }
        } catch (Throwable $e) {
            return null;
        }
    }
    return $out;
}

$jour = array(ts('2026-09-28 00:00'), ts('2026-09-28 23:59:59')); /* un lundi */

verifie('Chaque minute : 1440 exécutions par jour',
    count(calendrierbeCron::between('* * * * *', $jour[0], $jour[1])), 1440);
verifie('Toutes les 5 min : 288',
    count(calendrierbeCron::between('*/5 * * * *', $jour[0], $jour[1])), 288);
verifie('Heure fixe',
    dates(calendrierbeCron::between('29 09 * * *', $jour[0], $jour[1])), array('2026-09-28 09:29'));
verifie('Sixième champ : l\'année demandée',
    dates(calendrierbeCron::between('30 14 25 12 * 2026', ts('2026-09-28'), ts('2027-12-31'))), array('2026-12-25 14:30'));
verifie('Sixième champ : une autre année ne donne rien',
    calendrierbeCron::between('30 14 25 12 * 2025', ts('2026-09-28'), ts('2027-12-31')), array());
verifie('Borne de début respectée à la minute près',
    dates(calendrierbeCron::between('0,30 10 * * *', ts('2026-09-28 10:15'), ts('2026-09-28 23:00'))), array('2026-09-28 10:30'));
verifie('Heure d\'été : 02:30 part à 03:30 le 29 mars 2026, comme dans le coeur',
    dates(calendrierbeCron::between('30 2 * * *', ts('2026-03-29 00:00'), ts('2026-03-29 23:59'))), array('2026-03-29 03:30'));
verifie('Heure d\'hiver : 02:30 part une fois, au second passage',
    calendrierbeCron::between('30 2 * * *', ts('2026-10-25 00:00'), ts('2026-10-25 23:59')), array(ts('2026-10-25 02:30 CET')));
verifie('Limite respectée',
    count(calendrierbeCron::between('* * * * *', $jour[0], $jour[1], 10)), 10);

/* Ce que le coeur refuse, le calendrier le refuse. */
verifie('« 5/* » refusé', calendrierbeCron::parse('5/* * * * *'), null);
verifie('« * /0 » refusé', calendrierbeCron::parse('*/0 * * * *'), null);
verifie('« * /05 » refusé comme par checkAndFixCron()', calendrierbeCron::parse('*/05 * * * *'), null);
verifie('Deux espaces : un champ perdu, jamais lancé', calendrierbeCron::parse('0  12 * * *'), null);
verifie('Expression Jeedom : pas un cron', calendrierbeCron::parse('#[Maison][Soleil][Lever]#'), null);
verifie('Nombre de champs insuffisant', calendrierbeCron::parse('* * *'), null);

/* Tâche ponctuelle : « minute heure jour mois * », sans année. */
verifie('Prochaine occurrence d\'une tâche ponctuelle',
    date('Y-m-d H:i', calendrierbeCron::next('45 16 03 10 *', ts('2026-09-28 10:00'))), '2026-10-03 16:45');
verifie('Tâche ponctuelle déjà passée : l\'an prochain',
    date('Y-m-d H:i', calendrierbeCron::next('45 16 03 09 *', ts('2026-09-28 10:00'))), '2027-09-03 16:45');
verifie('29 février : trouvé en 2028',
    date('Y-m-d H:i', calendrierbeCron::next('0 12 29 2 *', ts('2026-09-25 10:00'))), '2028-02-29 12:00');
verifie('Occurrence précédente dans le mois',
    date('Y-m-d H:i', calendrierbeCron::previous('45 16 20 09 *', ts('2026-09-28 10:00'))), '2026-09-20 16:45');
verifie('Pas d\'occurrence précédente dans le mois',
    calendrierbeCron::previous('45 16 03 10 *', ts('2026-09-28 10:00')), null);

verifie('Description : chaque minute', calendrierbeCron::describe('* * * * *'), 'chaque minute');
verifie('Description : toutes les 5 min', calendrierbeCron::describe('*/5 * * * *'), 'toutes les 5 min');
verifie('Description : chaque heure', calendrierbeCron::describe('11 * * * *'), 'chaque heure à :11');
verifie('Description : « * /90 » part à :30, pas toutes les 90 min', calendrierbeCron::describe('*/90 * * * *'), 'chaque heure à :30');
verifie('Description : rien pour un horaire quotidien', calendrierbeCron::describe('29 09 * * *'), '');

/* Le coeur fait foi : chaque forme, sur des périodes ordinaires et sur les
 * deux jours de changement d'heure, doit donner exactement ses minutes. */
$formes = array('* * * * *', '*/7 * * * *', '5-50/15 * * * *', '0 7-21/2 * * *', '30 8 * * 1-5', '0 12 1 * 1',
    '0 0 1 */2 *', '15 3 * * SUN', '0 22 * * 0,6', '@hourly', '0 12 * * 6-0', '0 12 * * MON-SUN', '*/90 * * * *',
    '0 */36 * * *', '0,50-10 12 * * *', '0 12 L * *', '0 12 15W * *', '0 12 * * 1#2', '30 2 * * *', '30 3 * * *',
    '*/10 * * * *', '0 12 ? * 1', '0 12 * * MON-FRI/2', '30 14 25 * * 2026');
$periodes = array(
    array(ts('2026-09-28 00:00'), ts('2026-10-04 23:59')),
    array(ts('2026-03-28 00:00'), ts('2026-03-30 23:59')),
    array(ts('2026-10-24 00:00'), ts('2026-10-26 23:59')),
);
foreach ($formes as $forme) {
    $ecart = '';
    foreach ($periodes as $periode) {
        $nous = calendrierbeCron::between($forme, $periode[0], $periode[1]);
        $lui = coeur($forme, $periode[0], $periode[1]);
        if ($nous !== $lui) {
            $ecart = date('d/m', $periode[0]) . ' : ' . count((array) $nous) . ' contre ' . count((array) $lui);
            break;
        }
    }
    verifie('Comme le coeur : « ' . $forme . ' »', $ecart, '');
}

verifie('Date ISO', calendrierbeCollecte::parseDate('2026-10-01'), array('ts' => ts('2026-10-01 00:00'), 'allDay' => true));
verifie('Date ISO avec heure', calendrierbeCollecte::parseDate('2026-11-22 03:15:00'), array('ts' => ts('2026-11-22 03:15'), 'allDay' => false));
verifie('Date ISO en UTC', calendrierbeCollecte::parseDate('2026-10-01T10:00:00Z'), array('ts' => ts('2026-10-01 12:00'), 'allDay' => false));
verifie('Date ISO avec décalage', calendrierbeCollecte::parseDate('2026-10-01T10:00:00+00:00'), array('ts' => ts('2026-10-01 12:00'), 'allDay' => false));
verifie('Date française', calendrierbeCollecte::parseDate('01/10/2026 07:30'), array('ts' => ts('2026-10-01 07:30'), 'allDay' => false));
verifie('Timestamp : une heure précise', calendrierbeCollecte::parseDate('1790000000'), array('ts' => 1790000000, 'allDay' => false));
verifie('Heure seule : jour inconnu', calendrierbeCollecte::parseDate('07:09'), null);
verifie('Phrase : jour inconnu', calendrierbeCollecte::parseDate('demain 07:12'), null);
verifie('Date impossible', calendrierbeCollecte::parseDate('2026-02-30'), null);
verifie('Valeur JSON', calendrierbeCollecte::parseDate('[{"label":"jeudi 01/10"}]'), null);

verifie('Lien Jeedom accepté', calendrierbeCollecte::safeLink('index.php?v=d&p=scenario&id=3'), 'index.php?v=d&p=scenario&id=3');
verifie('Lien web accepté', calendrierbeCollecte::safeLink('https://example.org/'), 'https://example.org/');
verifie('Lien javascript: refusé', calendrierbeCollecte::safeLink('javascript:alert(1)'), '');

/* Performance : six semaines d'une tâche à la minute doivent rester instantanées. */
$t = microtime(true);
calendrierbeCron::between('* * * * *', ts('2026-09-28'), ts('2026-11-08'));
verifie('Six semaines « chaque minute », changement d\'heure compris, en moins de 0,5 s', (microtime(true) - $t) < 0.5, true);

echo ($echecs == 0) ? "\nTout est bon.\n" : "\n$echecs échec(s).\n";
exit($echecs == 0 ? 0 : 1);
