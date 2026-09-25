<?php
/* Contrôles par réflexion contre le coeur de Jeedom installé.
 *
 *   php tests/check-classes.php
 *
 * Ces pièges ont ceci de commun qu'ils sont invisibles à la relecture,
 * invisibles à « php -l », et invisibles au jeu d'essai hors ligne : ils ne se
 * manifestent que dans un vrai Jeedom, et leur symptôme ne ressemble pas à leur
 * cause. Tous se vérifient en quelques lignes de réflexion. */

$core = '/var/www/html/core/php/core.inc.php';
if (!is_readable($core)) {
    echo "Jeedom introuvable : contrôle ignoré.\n";
    exit(0);
}
require_once $core;

$file = __DIR__ . '/../core/class/calendrierbe.class.php';
$source = file_get_contents($file);
$problems = array();

/* ------------------------------------------------------------------ 1 ---
 * Toute propriété d'une classe eqLogic ou cmd doit commencer par un souligné.
 * DB::save() traite les autres comme des colonnes de la table : une propriété
 * « $refreshError » fait échouer la création d'un équipement sur « Unknown
 * column », sans que le journal du plugin en dise un mot. */
/* Les propriétés statiques comprises : DB::getFields() les lit elles aussi
 * par réflexion, sans distinguer. */
preg_match_all('/^\s*(?:private|protected|public)\s+(?:static\s+)?(?!function)\$(\w+)/m', $source, $m);
foreach ($m[1] as $name) {
    if (strpos($name, '_') !== 0) {
        $problems[] = 'Propriété sans souligné initial : $' . $name
            . ' — DB::save() la prendra pour une colonne de la table.';
    }
}

/* ------------------------------------------------------------------ 2 ---
 * Aucune méthode ne doit s'appeler « set » suivi d'une clé du formulaire, et
 * surtout pas setCmd(). À l'enregistrement, utils::a2o() appelle « set » + clé
 * pour chaque clé reçue, et la page envoie toujours une clé « cmd » : une
 * méthode privée de ce nom tue la sauvegarde sur une erreur fatale, avant toute
 * écriture. La page se rafraîchit, la saisie disparaît, le journal reste muet. */
$forbidden = array('setId', 'setName', 'setLogicalId', 'setGeneric_type', 'setObject_id',
                   'setEqType_name', 'setIsVisible', 'setIsEnable', 'setConfiguration',
                   'setTimeout', 'setCategory', 'setDisplay', 'setOrder', 'setComment',
                   'setTags', 'setCmd');
foreach ($forbidden as $name) {
    if (preg_match('/function\s+' . $name . '\s*\(/i', $source)) {
        $problems[] = 'Méthode interdite : ' . $name . '() — utils::a2o() l\'appellera à '
            . 'chaque enregistrement et tuera la sauvegarde.';
    }
}

/* ------------------------------------------------------------------ 3 ---
 * Une méthode héritée ne peut pas voir sa visibilité réduite. eqLogic et cmd
 * exposent publiquement getCache(), setCache(), getStatus(), setStatus() et bien
 * d'autres : les redéclarer en privé est une erreur fatale AU CHARGEMENT de la
 * classe. Or le coeur charge la classe de chaque plugin actif sur chaque page —
 * toute l'interface de Jeedom tombe alors en HTTP 500, pas seulement le plugin. */
preg_match_all('/^\s*(private|protected|public)\s+(?:static\s+)?function\s+(\w+)/m', $source, $m, PREG_SET_ORDER);
$rank = array('private' => 0, 'protected' => 1, 'public' => 2);
foreach (array('eqLogic', 'cmd') as $parent) {
    $ref = new ReflectionClass($parent);
    foreach ($m as $declaration) {
        $visibility = $declaration[1];
        $name = $declaration[2];
        if (!$ref->hasMethod($name)) {
            continue;
        }
        $inherited = $ref->getMethod($name);
        $parentVisibility = $inherited->isPrivate() ? 'private'
            : ($inherited->isProtected() ? 'protected' : 'public');
        if ($rank[$visibility] < $rank[$parentVisibility]) {
            $problems[] = 'Visibilité réduite sur une méthode héritée : ' . $name . '() est '
                . $visibility . ' ici et ' . $parentVisibility . ' dans ' . $parent
                . ' — erreur fatale au chargement, Jeedom entier en HTTP 500.';
        }
    }
}

/* ------------------------------------------------------------------ 4 ---
 * La classe de commande est obligatoire, même vide : core/ajax/eqLogic.ajax.php
 * refuse de créer ou d'ouvrir un équipement si elle manque. */
if (!preg_match('/class\s+calendrierbeCmd\s+extends\s+cmd/', $source)) {
    $problems[] = 'Classe calendrierbeCmd absente — impossible de créer un équipement.';
}

/* ------------------------------------------------------------------ 5 ---
 * Les points d'entrée que le coeur appelle sur la CLASSE et non sur un objet
 * doivent être déclarés static et publics.
 *
 * desktop/php/health.php teste method_exists() puis appelle <plugin>::health()
 * en statique : une méthode d'instance passe le test et lève une Error à
 * l'appel. Or le coeur l'entoure d'un catch (Exception), qui n'attrape pas les
 * Error de PHP 8. Ce n'est donc pas le plugin qui tombe, mais la page Santé de
 * toute l'installation, en HTTP 500. Le même raisonnement vaut pour les crons. */
$staticHooks = array('health', 'cron', 'cron5', 'cron10', 'cron15', 'cron30',
                     'cronHourly', 'cronDaily', 'deamon_info', 'deamon_start',
                     'deamon_stop', 'deamon_changeAutoMode', 'dependancy_info',
                     'dependancy_install', 'templateWidget', 'pull');
foreach ($staticHooks as $hook) {
    if (preg_match('/^\s*(private|protected|public)(\s+static)?\s+function\s+' . $hook . '\s*\(/mi', $source, $m)) {
        if (!isset($m[2]) || trim($m[2]) === '') {
            $problems[] = 'Point d\'entrée non statique : ' . $hook . '() — le coeur l\'appelle sur la classe, '
                . 'l\'Error qui en résulte n\'est pas rattrapée et emporte la page qui l\'invoque.';
        }
        if (isset($m[1]) && $m[1] !== 'public') {
            $problems[] = 'Point d\'entrée non public : ' . $hook . '() — le coeur ne pourra pas l\'appeler.';
        }
    }
}

/* ------------------------------------------------------------------ 6 ---
 * Aucun nom de commande ne doit contenir d'apostrophe ni les neuf autres
 * caractères que cleanComponanteName() (core/php/utils.inc.php) RETIRE
 * silencieusement : « Niveau d'aspiration » devient « Niveau daspiration » sur
 * le tableau de bord, et rien n'en avertit. */
preg_match_all('/\x27name\x27\s*=>\s*__\(\x27((?:[^\x27\\\\]|\\\\.)*)\x27/', $source, $m);
$interdits = array("\\'" => "'", '&' => '&', '#' => '#', ']' => ']', '[' => '[',
                   '%' => '%', '/' => '/', '"' => '"', '*' => '*');
foreach (array_unique($m[1]) as $label) {
    foreach ($interdits as $motif => $caractere) {
        if (strpos($label, $motif) !== false) {
            $problems[] = 'Nom de commande contenant « ' . $caractere . ' » : '
                . str_replace("\\'", "'", $label)
                . ' — cmd::setName() le retirera sans prévenir.';
            break;
        }
    }
}

/* ------------------------------------------------------------------ 7 ---
 * Les types génériques posés sur les commandes doivent exister dans le coeur.
 * Un type inventé n'est pas refusé : il est enregistré tel quel, et la commande
 * devient invisible pour tout ce qui range les équipements par type générique —
 * les assistants vocaux, les widgets, la vue Maison. Le plugin paraît alors
 * fonctionner, et elle n'apparaît nulle part ailleurs. */
$generics = jeedom::getConfiguration('cmd::generic_type');
preg_match_all('/\x27generic\x27\s*=>\s*\x27([A-Z_]+)\x27/', $source, $m);
foreach (array_unique($m[1]) as $generic) {
    if ($generic !== '' && !isset($generics[$generic])) {
        $problems[] = 'Type générique inconnu du coeur : ' . $generic . ' — la commande sera ignorée '
            . 'par tout ce qui range les équipements par type générique.';
    }
}

/* ------------------------------------------------------------------ 9 ---
 * Un « Deny from all » au mauvais endroit coupe le plugin sans rien dire.
 *
 * Les dossiers servis au navigateur — core/ajax, desktop — doivent rester
 * accessibles : un .htaccess qui les ferme fait échouer chaque appel du plugin
 * en 403, et rien ne le montre côté Jeedom. L'interface tourne dans le vide, le
 * journal du plugin reste muet, et la cause n'apparaît que dans
 * /var/www/html/log/http.error : « client denied by server configuration ».
 *
 * C'est arrivé, sur core/ajax : le sélecteur de lampes du plugin frère tournait
 * indéfiniment.
 *
 * À l'inverse, plugin_info doit rester fermé SAUF aux images, sinon l'icône du
 * plugin est refusée et le menu de Jeedom affiche une image cassée. */
$racine = __DIR__ . '/..';
foreach (array('core/ajax', 'desktop', 'desktop/php', 'desktop/js', 'desktop/modal') as $dossier) {
    if (file_exists($racine . '/' . $dossier . '/.htaccess')) {
        $problems[] = 'Dossier servi au navigateur protégé par un .htaccess : ' . $dossier
            . ' — chaque appel finira en 403, sans trace côté Jeedom.';
    }
}
$icone = $racine . '/plugin_info/.htaccess';
if (file_exists($icone)) {
    $contenu = file_get_contents($icone);
    if (strpos($contenu, 'allow from all') === false) {
        $problems[] = 'plugin_info/.htaccess ferme tout : l\'icône du plugin sera refusée '
            . 'et le menu affichera une image cassée. Ajouter l\'exception <Files> sur les images.';
    }
}

/* ----------------------------------------------------------------- 10 ---
 * Les classes auxiliaires ne sont pas connues de l'autoload du coeur.
 *
 * jeedom::autoload() ne sait charger que la classe qui porte le nom du plugin
 * (core/php/core.inc.php) : calendrierbeCron et calendrierbeCollecte n'existent que
 * parce que calendrierbe.class.php les require. Un fichier servi au navigateur —
 * page, modale, ajax, page de configuration — qui nomme l'une des deux avant
 * d'avoir touché à calendrierbe meurt donc sur « Class not found ».
 *
 * Et le symptôme ne désigne pas la cause : la page reste vide, le journal du
 * plugin ne dit rien, tout est dans /var/www/html/log/http.error. C'est arrivé
 * sur plugin_info/configuration.php, à sa première ouverture.
 */
$racine = __DIR__ . '/..';
$servis = array('plugin_info/configuration.php', 'plugin_info/install.php',
                'core/ajax/calendrierbe.ajax.php', 'desktop/php/calendrierbe.php');
foreach ($servis as $fichier) {
    $chemin = $racine . '/' . $fichier;
    if (!file_exists($chemin)) {
        continue;
    }
    $contenu = file_get_contents($chemin);
    if (!preg_match('/calendrierbe(Cron|Collecte)::/', $contenu)) {
        continue;
    }
    /* Soit le fichier charge la classe principale lui-même, soit il a nommé
     * calendrierbe avant, ce qui déclenche l'autoload et amène les deux autres
     * avec lui. */
    if (preg_match('/require_once[^;]*calendrierbe\.class\.php/', $contenu)) {
        continue;
    }
    preg_match('/calendrierbe(Cron|Collecte)?::/', $contenu, $premier);
    if (!isset($premier[1]) || $premier[1] === '') {
        continue;
    }
    $problems[] = 'Classe auxiliaire nommée sans chargement : ' . $fichier
        . ' appelle calendrierbe' . $premier[1] . ':: sans require_once de la classe principale '
        . '— l\'autoload du coeur ne la connaît pas, la page meurt sur « Class not found ».';
}

/* ----------------------------------------------------------------- 11 ---
 * Le calendrier déroule les expressions cron par champ, pour la vitesse. Il
 * doit tomber exactement sur les minutes où jeeCron lance réellement : on
 * rejoue ici cronIsDue() du coeur minute par minute, sur les programmations
 * réelles de l'installation, pour la semaine qui vient. Un écart, et le
 * calendrier annonce une heure où rien ne part. */
require_once __DIR__ . '/../core/class/calendrierbeCron.class.php';
$echantillon = array();
foreach (cron::all() as $cron) {
    $echantillon[] = $cron->getSchedule();
}
foreach (scenario::all() as $scenario) {
    foreach ((array) $scenario->getSchedule() as $schedule) {
        $echantillon[] = $schedule;
    }
}
$debut = strtotime('tomorrow');
$fin = $debut + 7 * 86400 - 1;
foreach (array_unique($echantillon) as $expression) {
    $nous = calendrierbeCron::between($expression, $debut, $fin);
    if ($nous === null) {
        continue;
    }
    $eux = array();
    for ($t = $debut; $t <= $fin; $t += 60) {
        /* cronIsDue() lit l'heure courante : on ne peut l'appeler que pour
         * « maintenant ». Sa logique est reprise ici, isDue() compris. */
        $jour = new DateTime('@' . $t);
        $jour->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $minuit = (clone $jour)->modify('today midnight');
        $demain = (clone $jour)->modify('today midnight +1 day');
        if (($demain->format('I') - $minuit->format('I')) == -1 && date('I', $t) == 1 && date('Gi', $t) > 159) {
            continue;
        }
        $parts = explode(' ', trim($expression));
        if (count($parts) == 6 && $parts[5] != '*' && $parts[5] != date('Y', $t)) {
            continue;
        }
        try {
            $c = new Cron\CronExpression(checkAndFixCron($expression), new Cron\FieldFactory);
            if ($c->isDue(date('Y-m-d H:i:s', $t))) {
                $eux[] = $t;
            }
        } catch (Throwable $e) {
            break;
        }
    }
    if ($nous !== $eux) {
        $problems[] = 'Écart avec le coeur sur « ' . $expression . ' » : '
            . count($nous) . ' exécutions contre ' . count($eux) . '.';
    }
}

/* ---------------------------------------------------------------- BILAN --- */
if (empty($problems)) {
    echo "Contrôles du coeur : aucun problème.\n";
    exit(0);
}
echo "Contrôles du coeur : " . count($problems) . " problème(s)\n";
foreach ($problems as $problem) {
    echo '  - ' . $problem . "\n";
}
exit(1);
