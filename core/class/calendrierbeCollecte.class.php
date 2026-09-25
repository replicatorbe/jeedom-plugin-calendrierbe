<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/calendrierbeCron.class.php';

/*
 * Rassemble tout ce que Jeedom exécutera entre deux dates, sans rien toucher.
 *
 * Tout ce qui est programmé dans Jeedom finit dans l'une de ces sources :
 *
 *  1. Les scénarios en mode « programmé » ou « les deux », dont la
 *     programmation (une ou plusieurs expressions) est lue par scenario::check()
 *     chaque minute. Aucune ligne dans la table cron : il faut la dérouler.
 *  2. La table cron, que jeeCron.php parcourt chaque minute :
 *     - les tâches ponctuelles (once = 1), créées par les blocs A et DANS des
 *       scénarios (scenario::doIn), les retours d'état et les actions sur valeur
 *       des commandes (cmd::returnState, cmd::cmdAlert, cmd::duringAlertLevel),
 *       les interactions différées (interactQuery::doIn), et tout ce qu'un
 *       plugin confie à utils::executeAsync() ;
 *     - les tâches récurrentes des plugins (policebecheck::pull…) ;
 *     - les tâches du coeur, dont plugin::cron… qui appellent à leur tour les
 *       fonctions cron() de chaque plugin actif.
 *  3. L'actualisation automatique (configuration « autorefresh ») que les
 *     plugins comme virtual ou script évaluent dans leur cron().
 *  4. Les prévisions des plugins : méthode calendrierbeEvents() pour ceux qui
 *     l'ont, et à défaut les commandes info « next… » dont la valeur est une
 *     date.
 *
 * Ne se prévoient pas, et ne sont donc pas là : les déclenchements sur
 * événement, les sleep et wait internes aux scénarios, et les programmations
 * qu'un plugin évalue lui-même dans son cron() sans les exposer.
 *
 * Rien ici n'écrit quoi que ce soit : ni en base, ni dans le cache. C'est une
 * vue, que l'on ouvre souvent, et qui ne doit pas avoir d'effet de bord.
 */
class calendrierbeCollecte {

    /* Les tâches du coeur qui ne font que faire tourner la machine : utiles à
     * connaître, mais les montrer par défaut noierait le calendrier. */
    const ENGINE = array(
        'scenario::check', 'scenario::control', 'jeedom::cron', 'jeedom::cron5', 'jeedom::cron10',
        'queue::cron', 'cache::persist', 'network::cron10', 'plugin::checkDeamon', 'plugin::heartbeat',
    );

    /* Les classes du coeur : leurs tâches vont dans « Tâches de Jeedom ». */
    const CORE_CLASSES = array('jeedom', 'plugin', 'scenario', 'cache', 'history', 'network', 'queue',
                               'cron', 'log', 'update', 'cmd', 'eqLogic', 'message', 'user', 'listener');

    const PLUGIN_FUNCTIONS = array(
        'plugin::cron'       => 'cron',
        'plugin::cron5'      => 'cron5',
        'plugin::cron10'     => 'cron10',
        'plugin::cron15'     => 'cron15',
        'plugin::cron30'     => 'cron30',
        'plugin::cronHourly' => 'cronHourly',
        'plugin::cronDaily'  => 'cronDaily',
    );

    /*
     * Une tâche qui part chaque jour ferait répéter son titre, son détail et son
     * lien à chacune de ses lignes : sur six semaines, près d'un demi-mégaoctet
     * de texte identique. La description va donc une fois dans « tasks », et
     * chaque exécution n'en garde que la clé et ce qui lui est propre.
     */
    const OCCURRENCE_FIELDS = array('count', 'last', 'times', 'late', 'failed', 'planned', 'frequency');

    /* Une tâche ponctuelle dont l'heure est passée depuis plus longtemps que
     * cela n'est plus « en retard » : elle attend l'an prochain. */
    const LATE_HORIZON_DAYS = 31;

    private $_from;
    private $_to;
    private $_now;
    private $_threshold;
    private $_items = array();
    private $_tasks = array();
    private $_warnings = array();
    private $_plugins = null;
    private $_hookPlugins = array();
    private $_cronOff = false;
    private $_scenarioOff = '';

    /*
     * $_threshold : au-delà de ce nombre d'exécutions dans une journée, une
     * tâche est résumée en une seule ligne pour ce jour-là (« 288 fois, toutes
     * les 5 min »). En dessous, chaque exécution a sa ligne.
     */
    public function __construct($_from, $_to, $_threshold = 12) {
        $this->_now = time();
        /* Le passé n'intéresse pas ce calendrier : ce qui a tourné est dans les
         * journaux, pas dans une prévision. La minute en cours compte encore. */
        $this->_from = max((int) $_from, $this->_now - $this->_now % 60);
        $this->_to = (int) $_to;
        $this->_threshold = max(1, (int) $_threshold);
    }

    public static function collect($_from, $_to, $_threshold = 12) {
        $collecte = new self($_from, $_to, $_threshold);
        return $collecte->run();
    }

    public function run() {
        $this->checkEngines();
        if ($this->_to >= $this->_from) {
            /* Les hooks passent avant les commandes « next » : un plugin qui
             * publie son planning n'a pas à apparaître deux fois. */
            foreach (array('collectScenarios', 'collectCrons', 'collectAutorefresh', 'collectPluginHooks', 'collectNextCommands') as $method) {
                try {
                    $this->$method();
                } catch (Throwable $e) {
                    /* Une source en défaut ne doit pas vider tout le calendrier. */
                    $this->_warnings[] = $method . ' : ' . $e->getMessage();
                    log::add('calendrierbe', 'error', $method . ' : ' . $e->getMessage());
                }
            }
        }
        usort($this->_items, function ($a, $b) {
            return ($a['ts'] == $b['ts']) ? strcmp($a['task'], $b['task']) : $a['ts'] - $b['ts'];
        });
        return array(
            'from'     => $this->_from,
            'to'       => $this->_to,
            'now'      => $this->_now,
            'timezone' => date_default_timezone_get(),
            'tasks'    => $this->_tasks,
            'items'    => $this->_items,
            'warnings' => $this->_warnings,
        );
    }

    /*
     * Ce qui empêche le coeur de lancer quoi que ce soit, dit une fois en tête
     * du calendrier plutôt que découvert en se demandant pourquoi rien ne part.
     */
    private function checkEngines() {
        if (config::byKey('enableCron', 'core', 1) == 0) {
            $this->_cronOff = true;
            $this->_warnings[] = __('Le moteur de tâches de Jeedom est désactivé : rien de ce qui est affiché ne partira.', __FILE__);
        }
        if (config::byKey('enableScenario', 'core', 1) != 1) {
            $this->_scenarioOff = __('moteur de scénarios désactivé', __FILE__);
        } else {
            /* scenario::check est la tâche qui lance tous les scénarios
             * programmés : désactivée ou absente, plus aucun ne part. */
            $check = cron::byClassAndFunction('scenario', 'check');
            if (!is_object($check) || $check->getEnable() != 1) {
                $this->_scenarioOff = __('tâche scenario::check désactivée', __FILE__);
            }
        }
        if ($this->_scenarioOff !== '') {
            $this->_warnings[] = sprintf(__('Aucun scénario programmé ne partira : %s.', __FILE__), $this->_scenarioOff);
        }
        if (!jeedom::isStarted()) {
            $this->_warnings[] = __('Jeedom n\'a pas fini de démarrer : seules ses tâches internes tournent pour l\'instant.', __FILE__);
        }
        if (config::byKey('maxCatchAllow', 'core', 30) == -1) {
            $this->_warnings[] = __('Le rattrapage des scénarios est réglé sur -1 : le coeur relance alors chaque scénario programmé à chaque minute.', __FILE__);
        }
    }

    /* ============================================================ AJOUTS */

    /*
     * Range les exécutions d'une même tâche par jour : une ligne par exécution
     * tant que la journée en compte peu, une ligne résumée sinon.
     */
    private function addOccurrences($_timestamps, $_base, $_frequency = '') {
        $byDay = array();
        foreach ($_timestamps as $ts) {
            $byDay[date('Y-m-d', $ts)][] = $ts;
        }
        /* Le choix se fait pour la tâche, pas pour la journée : le soir, il
         * ne reste que quelques passages d'une tâche « toutes les 10 min »,
         * et ils s'étaleraient sur autant de lignes alors que la même tâche
         * tient en une seule les jours suivants. */
        $summarize = false;
        foreach ($byDay as $list) {
            $summarize = $summarize || count($list) > $this->_threshold;
        }
        foreach ($byDay as $list) {
            if ($summarize && count($list) > 1) {
                $times = array();
                foreach ($list as $ts) {
                    $times[] = date('H:i', $ts);
                }
                $this->push($list[0], $_base + array(
                    'count'     => count($list),
                    'last'      => date('H:i', end($list)),
                    'frequency' => $_frequency,
                    /* La liste n'a d'intérêt que tant qu'elle se lit : « chaque
                     * heure » oui, « toutes les 5 min » non. Au-delà, elle ne
                     * ferait qu'alourdir la réponse — les tâches à la minute la
                     * multiplieraient par centaines de kilo-octets. */
                    'times'     => (count($times) <= 48) ? $times : array(),
                ));
            } else {
                foreach ($list as $ts) {
                    $this->push($ts, $_base);
                }
            }
        }
    }

    private function push($_ts, $_item) {
        $task = array_merge(array(
            'category' => 'systeme',
            'title'    => '',
            'detail'   => '',
            'source'   => '',
            'link'     => '',
            'dynamic'  => false,
            'disabled' => '',
        ), array_diff_key($_item, array_flip(self::OCCURRENCE_FIELDS)));
        /* Le moteur de tâches coupé arrête tout ce qui passe par jeeCron. */
        if ($this->_cronOff && $task['disabled'] === '') {
            $task['disabled'] = __('moteur de tâches désactivé', __FILE__);
        }
        $task['link'] = self::safeLink($task['link']);
        $key = ($task['source'] !== '') ? $task['source'] : md5($task['category'] . $task['title'] . $task['detail']);
        if (!isset($this->_tasks[$key])) {
            $this->_tasks[$key] = $task;
        }
        $occurrence = array('task' => $key, 'ts' => $_ts, 'date' => date('Y-m-d', $_ts), 'time' => date('H:i', $_ts));
        foreach (self::OCCURRENCE_FIELDS as $field) {
            if (isset($_item[$field]) && $_item[$field] !== false && $_item[$field] !== array() && $_item[$field] !== '') {
                $occurrence[$field] = $_item[$field];
            }
        }
        $this->_items[] = $occurrence;
    }

    /* Un lien vers une page de Jeedom ou une adresse web, rien d'autre : un
     * « javascript: » venu d'un plugin tiers n'a rien à faire dans la page. */
    public static function safeLink($_link) {
        $link = trim((string) $_link);
        if ($link === '' || preg_match('#^(index\.php\?|https?://)#i', $link)) {
            return $link;
        }
        return '';
    }

    /* Les plugins actifs, chargés une fois : listPlugin() lit chaque info.json. */
    private function plugins() {
        if ($this->_plugins === null) {
            $this->_plugins = array();
            foreach (plugin::listPlugin(true) as $plugin) {
                $this->_plugins[$plugin->getId()] = $plugin;
            }
        }
        return $this->_plugins;
    }

    /* ======================================================== SCÉNARIOS */

    private function collectScenarios() {
        foreach (scenario::all() as $scenario) {
            if ($scenario->getIsActive() != 1 || !in_array($scenario->getMode(), array('schedule', 'all'))) {
                continue;
            }
            $schedules = $scenario->getSchedule();
            if (!is_array($schedules)) {
                $schedules = array($schedules);
            }
            $base = array(
                'category' => 'scenario',
                'title'    => $scenario->getName(),
                'source'   => 'scenario#' . $scenario->getId(),
                'detail'   => $scenario->getHumanName(),
                'link'     => 'index.php?v=d&p=scenario&id=' . $scenario->getId(),
                'disabled' => $this->_scenarioOff,
            );
            /* Plusieurs programmations se combinent en OU, et scenario::isDue()
             * ne lance qu'une fois par minute : deux expressions qui tombent
             * sur la même minute font une seule exécution. */
            $timestamps = array();
            $frequencies = array();
            $count = 0;
            foreach ($schedules as $schedule) {
                $schedule = trim((string) $schedule);
                if ($schedule === '') {
                    continue;
                }
                $count++;
                $list = calendrierbeCron::between($schedule, $this->_from, $this->_to);
                if ($list === null) {
                    $list = $this->expressionSchedule($schedule);
                    if ($list !== null) {
                        $base['dynamic'] = true;
                        $base['detail'] .= ' — ' . sprintf(__('programmation calculée « %s », projetée avec sa valeur actuelle', __FILE__), $schedule);
                    }
                } else {
                    $frequencies[] = calendrierbeCron::describe($schedule);
                    $base['detail'] .= ' — ' . sprintf(__('programmation « %s »', __FILE__), $schedule);
                }
                if ($list === null) {
                    $this->_warnings[] = sprintf(__('Programmation que Jeedom ne sait pas lire, pour le scénario %1$s : « %2$s »', __FILE__), $scenario->getHumanName(), $schedule);
                    continue;
                }
                foreach ($list as $ts) {
                    $timestamps[$ts] = true;
                }
            }
            $timestamps = array_keys($timestamps);
            sort($timestamps);
            $this->addOccurrences($timestamps, $base, ($count == 1 && count($frequencies) == 1) ? $frequencies[0] : '');
        }
    }

    /*
     * Une programmation qui n'est pas un cron — « #[Maison][Soleil][Lever]# » —
     * est évaluée chaque minute par cronIsDue() et comparée à date('Gi') : le
     * scénario part chaque jour à l'heure que vaut l'expression à cet instant.
     * On ne connaît que sa valeur d'aujourd'hui ; on la reporte sur chaque jour,
     * en le signalant comme une estimation.
     */
    private function expressionSchedule($_schedule) {
        try {
            $value = jeedom::evaluateExpression($_schedule);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int) $value;
        $hour = intdiv($value, 100);
        $minute = $value % 100;
        if ($value < 0 || $hour > 23 || $minute > 59) {
            return null;
        }
        return calendrierbeCron::between($minute . ' ' . $hour . ' * * *', $this->_from, $this->_to);
    }

    /* ======================================================= TABLE CRON */

    private function collectCrons() {
        foreach (cron::all() as $cron) {
            /* Un démon tourne en permanence : il n'a pas d'heure de départ. */
            if ($cron->getDeamon() == 1 || $cron->getEnable() != 1) {
                continue;
            }
            /* jeeCron ne lance que ce qui existe (jeeCron.php, class_exists et
             * method_exists) : la tâche d'un plugin désactivé ou désinstallé
             * reste dans la table, mais ne part plus. */
            $class = (string) $cron->getClass();
            $function = (string) $cron->getFunction();
            if ($class !== '' ? !(class_exists($class) && method_exists($class, $function)) : !function_exists($function)) {
                continue;
            }
            $key = $class . '::' . $function;
            $option = $cron->getOption();
            if (!is_array($option)) {
                $option = array();
            }
            if ($cron->getOnce() == 1) {
                $this->addOnce($cron, $key, $option);
                continue;
            }
            $schedule = $cron->getSchedule();
            $list = calendrierbeCron::between($schedule, $this->_from, $this->_to);
            if ($list === null) {
                continue;
            }
            $frequency = calendrierbeCron::describe($schedule);
            if (isset(self::PLUGIN_FUNCTIONS[$key])) {
                $this->addPluginFunction($key, self::PLUGIN_FUNCTIONS[$key], $list, $frequency, $schedule);
                continue;
            }
            $base = $this->describeCron($cron, $key, $option);
            $base['source'] = 'cron#' . $cron->getId();
            $base['detail'] = trim($base['detail'] . ' — ' . sprintf(__('programmation « %s »', __FILE__), $schedule), ' —');
            $this->addOccurrences($list, $base, $frequency);
        }
    }

    /*
     * Une tâche ponctuelle n'a pas d'année : cron::convertDateToCron() écrit
     * « minute heure jour mois * », et jeeCron la supprime après l'avoir lancée.
     *
     * Son heure se retrouve à partir de sa création. Le coeur pose bien un
     * lastRun à ce moment, mais avant le premier save(), quand la tâche n'a pas
     * encore d'id : la valeur part dans une clé de cache sans id, et la vraie
     * tâche n'a presque jamais de lastRun. On raisonne donc sur l'expression :
     * une occurrence dans le mois écoulé est une heure passée sans que la tâche
     * parte — elle restera en attente jusqu'à l'an prochain, jeeCron ne
     * rattrapant rien — et sinon c'est la prochaine qui compte.
     *
     * Une tâche restée là après être partie en erreur n'est pas « en retard » :
     * elle a été lancée, et a échoué. On le dit.
     */
    private function addOnce($_cron, $_key, $_option) {
        $schedule = $_cron->getSchedule();
        $state = $_cron->getState();
        if ($state == 'run' || $state == 'starting') {
            return;
        }
        $failed = in_array($state, array('error', 'Not found'));
        $lastRun = strtotime((string) $_cron->getLastRun());
        $planned = null;
        if ($lastRun && !$failed) {
            $planned = calendrierbeCron::next($schedule, $lastRun - 1);
        } else {
            $previous = calendrierbeCron::previous($schedule, $this->_now - 59, self::LATE_HORIZON_DAYS);
            $planned = ($previous !== null) ? $previous : calendrierbeCron::next($schedule, $this->_now - 60);
        }
        if ($planned === null) {
            return;
        }
        if ($_key == 'scenario::doIn' && isset($_option['second'])) {
            $planned += (int) $_option['second'];
        }
        $item = $this->describeCron($_cron, $_key, $_option);
        $item['source'] = 'cron#' . $_cron->getId();
        $item['once'] = true;
        $ts = $planned;
        if ($planned < $this->_now - 60) {
            /* Montrée aujourd'hui, là où l'on regarde, avec son heure prévue. */
            $ts = $this->_now;
            $item[$failed ? 'failed' : 'late'] = true;
            $item['planned'] = date('d/m H:i', $planned);
        }
        if ($ts < $this->_from - 60 || $ts > $this->_to) {
            return;
        }
        $this->push(max($ts, $this->_from), $item);
    }

    /* Le titre et le lien d'une ligne de la table cron, en clair. */
    private function describeCron($_cron, $_key, $_option) {
        $item = array('category' => 'plugin', 'title' => $_key, 'detail' => '', 'link' => '');
        switch ($_key) {
            case 'scenario::doIn':
                $item['category'] = 'bloc';
                $scenario = isset($_option['scenario_id']) ? scenario::byId($_option['scenario_id']) : null;
                if (is_object($scenario)) {
                    $item['title'] = $scenario->getName();
                    $item['detail'] = sprintf(__('Bloc A / DANS du scénario %s', __FILE__), $scenario->getHumanName());
                    $item['link'] = 'index.php?v=d&p=scenario&id=' . $scenario->getId();
                    /* scenario::doIn ne fait rien pour un scénario inactif ou
                     * quand le moteur de scénarios est coupé. */
                    if ($scenario->getIsActive() != 1) {
                        $item['disabled'] = __('scénario désactivé', __FILE__);
                    } elseif (config::byKey('enableScenario', 'core', 1) != 1) {
                        $item['disabled'] = __('moteur de scénarios désactivé', __FILE__);
                    }
                } else {
                    $item['title'] = __('Bloc A / DANS', __FILE__);
                    $item['detail'] = __('Scénario introuvable : la tâche ne fera rien', __FILE__);
                    $item['disabled'] = __('scénario introuvable', __FILE__);
                }
                return $item;
            case 'cmd::returnState':
            case 'cmd::cmdAlert':
            case 'cmd::duringAlertLevel':
                $item['category'] = 'commande';
                $cmd = isset($_option['cmd_id']) ? cmd::byId($_option['cmd_id']) : null;
                $name = is_object($cmd) ? $cmd->getHumanName() : ('#' . (isset($_option['cmd_id']) ? $_option['cmd_id'] : '?'));
                if ($_key == 'cmd::returnState') {
                    $item['title'] = sprintf(__('Retour d\'état de %s', __FILE__), $name);
                    if (is_object($cmd)) {
                        $item['detail'] = sprintf(__('Repassera à « %s »', __FILE__), $cmd->getConfiguration('returnStateValue'));
                    }
                } elseif ($_key == 'cmd::cmdAlert') {
                    $item['title'] = sprintf(__('Action sur valeur de %s', __FILE__), $name);
                    $item['detail'] = __('Si la condition est toujours vraie à cette heure-là', __FILE__);
                } else {
                    $level = isset($_option['level']) ? $_option['level'] : '';
                    $item['title'] = sprintf(__('Alerte « %1$s » de %2$s', __FILE__), $level, $name);
                    $item['detail'] = __('Si la valeur est toujours en alerte à cette heure-là', __FILE__);
                }
                if (is_object($cmd) && is_object($cmd->getEqLogic())) {
                    $item['link'] = $cmd->getEqLogic()->getLinkToConfiguration();
                }
                return $item;
            case 'cmd::sendHistoryInflux':
                return array('category' => 'systeme', 'title' => __('Envoi de l\'historique vers InfluxDB', __FILE__), 'detail' => $_key, 'link' => '');
            case 'interactQuery::doIn':
                $item['category'] = 'bloc';
                $item['title'] = __('Interaction différée', __FILE__);
                if (isset($_option['dictation']) && $_option['dictation'] !== '') {
                    $item['detail'] = '« ' . $_option['dictation'] . ' »';
                } else {
                    $query = isset($_option['interactQuery_id']) ? interactQuery::byId($_option['interactQuery_id']) : null;
                    if (is_object($query)) {
                        $item['detail'] = $query->getQuery();
                    }
                }
                $item['link'] = 'index.php?v=d&p=interact';
                return $item;
            case 'jeedom::backup':
                return array('category' => 'systeme', 'title' => __('Sauvegarde de Jeedom', __FILE__), 'detail' => $_key, 'link' => 'index.php?v=d&p=backup');
            case 'jeedom::cronDaily':
                return array('category' => 'systeme', 'title' => __('Maintenance quotidienne de Jeedom', __FILE__),
                             'detail' => __('Nettoyage des journaux, des tâches et de la base, contrôle de cohérence', __FILE__), 'link' => '');
            case 'jeedom::cronHourly':
                return array('category' => 'systeme', 'title' => __('Tâches horaires de Jeedom', __FILE__),
                             'detail' => __('Cache, vérification des mises à jour, journaux', __FILE__), 'link' => '');
            case 'history::archive':
                return array('category' => 'systeme', 'title' => __('Archivage de l\'historique', __FILE__),
                             'detail' => __('Lissage et purge des historiques des commandes', __FILE__), 'link' => '');
        }
        if (in_array($_key, self::ENGINE)) {
            return array('category' => 'moteur', 'title' => $_key, 'detail' => __('Tâche interne du moteur de Jeedom', __FILE__), 'link' => 'index.php?v=d&p=cron');
        }
        $class = (string) $_cron->getClass();
        if (in_array($class, self::CORE_CLASSES) || strpos($class, 'repo_') === 0) {
            $item['category'] = 'systeme';
            $item['link'] = 'index.php?v=d&p=cron';
            return $item;
        }
        /* Tâche d'un plugin : on la rattache à son équipement quand l'option
         * le désigne, ce que font la plupart (« id » ou « eqLogic_id »). */
        foreach (array('eqLogic_id', 'id') as $field) {
            if (isset($_option[$field]) && is_numeric($_option[$field])) {
                $eqLogic = eqLogic::byId($_option[$field]);
                if (is_object($eqLogic) && $eqLogic->getEqType_name() == $class) {
                    $item['detail'] = $eqLogic->getHumanName();
                    $item['link'] = $eqLogic->getLinkToConfiguration();
                    break;
                }
            }
        }
        /* Le nom du plugin ne se demande qu'aux plugins actifs, déjà chargés.
         * plugin::byId() sur une classe qui n'est pas un plugin DÉSACTIVE ce
         * plugin en base avant de lever son exception (forceDisablePlugin) :
         * une simple consultation du calendrier ne doit jamais faire cela. */
        $plugins = $this->plugins();
        if (isset($plugins[$class])) {
            $item['title'] = $plugins[$class]->getName() . ' — ' . $_cron->getFunction();
            if ($item['link'] === '') {
                $item['link'] = 'index.php?v=d&m=' . $class . '&p=' . $plugins[$class]->getIndex();
            }
        }
        if ($_cron->getOnce() == 1) {
            $item['detail'] = trim($item['detail'] . ' ' . __('(tâche ponctuelle)', __FILE__));
        }
        return $item;
    }

    /*
     * plugin::cron, plugin::cronDaily… n'exécutent rien d'eux-mêmes : ils
     * appellent la fonction du même nom de chaque plugin actif qui l'a et ne
     * l'a pas désactivée (functionality::<fn>::enable). C'est cette liste qu'on
     * montre, plutôt que le nom d'une mécanique interne.
     */
    private function addPluginFunction($_key, $_function, $_list, $_frequency, $_schedule) {
        $names = array();
        foreach ($this->plugins() as $id => $plugin) {
            if (!method_exists($id, $_function)) {
                continue;
            }
            if (config::byKey('functionality::' . $_function . '::enable', $id, 1) == 0) {
                continue;
            }
            $names[] = $plugin->getName();
        }
        if (empty($names)) {
            return;
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $this->addOccurrences($_list, array(
            'category' => 'fonction',
            'title'    => sprintf(__('Fonction %1$s des plugins (%2$d)', __FILE__), $_function, count($names)),
            'detail'   => implode(', ', $names) . ' — ' . sprintf(__('programmation « %s »', __FILE__), $_schedule),
            'source'   => $_key,
            'link'     => 'index.php?v=d&p=plugin',
        ), $_frequency);
    }

    /* ================================================ ACTUALISATION AUTO */

    /*
     * La configuration « autorefresh » d'un équipement est une expression cron
     * que son plugin (virtual, script, monitoring…) évalue dans son propre
     * cron(), chaque minute. Le coeur n'en fait rien lui-même : c'est une
     * convention, que l'on ne suit que pour les plugins actifs dont la fonction
     * cron tourne.
     */
    private function collectAutorefresh() {
        $rows = DB::Prepare("SELECT id FROM eqLogic WHERE isEnable = 1 AND configuration LIKE '%autorefresh%'", array(), DB::FETCH_TYPE_ALL);
        if (!is_array($rows)) {
            return;
        }
        $plugins = $this->plugins();
        foreach ($rows as $row) {
            $eqLogic = eqLogic::byId($row['id']);
            if (!is_object($eqLogic)) {
                continue;
            }
            $schedule = trim((string) $eqLogic->getConfiguration('autorefresh', ''));
            $type = $eqLogic->getEqType_name();
            if ($schedule === '' || !isset($plugins[$type]) || !method_exists($type, 'cron')
                || config::byKey('functionality::cron::enable', $type, 1) == 0) {
                continue;
            }
            $list = calendrierbeCron::between($schedule, $this->_from, $this->_to);
            if ($list === null) {
                continue;
            }
            $this->addOccurrences($list, array(
                'category' => 'plugin',
                'title'    => sprintf(__('Actualisation de %s', __FILE__), $eqLogic->getName()),
                'detail'   => $eqLogic->getHumanName() . ' — ' . sprintf(__('actualisation automatique « %s »', __FILE__), $schedule),
                'source'   => 'autorefresh#' . $eqLogic->getId(),
                'link'     => $eqLogic->getLinkToConfiguration(),
            ), calendrierbeCron::describe($schedule));
        }
    }

    /* ========================================================= PRÉVISIONS */

    /*
     * Un plugin peut exposer lui-même ce qu'il prévoit, avec une méthode
     * statique :
     *
     *   public static function calendrierbeEvents($_from, $_to) {
     *       return array(array('ts' => 1790000000, 'title' => 'Fermeture des volets',
     *                          'detail' => '[Salon][Volets]', 'link' => 'index.php?…'));
     *   }
     *
     * C'est le seul moyen de voir ce qu'un plugin calcule lui-même dans son
     * cron() — lever du soleil, simulation de présence — sans dépendre du
     * texte de ses commandes.
     */
    private function collectPluginHooks() {
        foreach ($this->plugins() as $id => $plugin) {
            if ($id == 'calendrierbe' || !class_exists($id) || !method_exists($id, 'calendrierbeEvents')) {
                continue;
            }
            $this->_hookPlugins[$id] = true;
            try {
                $events = $id::calendrierbeEvents($this->_from, $this->_to);
            } catch (Throwable $e) {
                $this->_warnings[] = $plugin->getName() . ' : ' . $e->getMessage();
                continue;
            }
            if (!is_array($events)) {
                continue;
            }
            foreach (array_values($events) as $index => $event) {
                if (!is_array($event) || !isset($event['ts']) || !is_numeric($event['ts'])) {
                    continue;
                }
                $ts = (int) $event['ts'];
                if ($ts < $this->_from || $ts > $this->_to) {
                    continue;
                }
                $this->push($ts, array(
                    'category' => 'prevision',
                    'title'    => isset($event['title']) ? (string) $event['title'] : $plugin->getName(),
                    'detail'   => isset($event['detail']) ? (string) $event['detail'] : $plugin->getName(),
                    /* Une clé par événement : ils ne partagent que leur plugin. */
                    'source'   => 'plugin#' . $id . '#' . $index,
                    'link'     => isset($event['link']) ? (string) $event['link'] : '',
                ));
            }
        }
    }

    /*
     * Les commandes info dont l'identifiant logique commence par « next » et
     * dont la valeur est une date complète : « 2026-10-01 », « 2026-11-22
     * 03:00 », « 01/10/2026 ». Une heure seule (« 07:09 ») ou une phrase
     * (« demain 07:12 ») ne dit pas de quel jour il s'agit : on ne l'invente pas.
     */
    private function collectNextCommands() {
        if (config::byKey('showNextCommands', 'calendrierbe', 1) != 1) {
            return;
        }
        $rows = DB::Prepare("SELECT c.id FROM cmd c INNER JOIN eqLogic e ON e.id = c.eqLogic_id
                             WHERE c.type = 'info' AND c.logicalId LIKE 'next%' AND e.isEnable = 1", array(), DB::FETCH_TYPE_ALL);
        if (!is_array($rows)) {
            return;
        }
        $plugins = $this->plugins();
        $today = date('Y-m-d', $this->_from);
        foreach ($rows as $row) {
            $cmd = cmd::byId($row['id']);
            if (!is_object($cmd) || !is_object($cmd->getEqLogic())) {
                continue;
            }
            $eqLogic = $cmd->getEqLogic();
            $type = $eqLogic->getEqType_name();
            /* Plugin inactif, ou qui publie déjà son planning par le hook. */
            if (!isset($plugins[$type]) || isset($this->_hookPlugins[$type])) {
                continue;
            }
            $date = self::parseDate($cmd->execCmd());
            if ($date === null || $date['ts'] > $this->_to) {
                continue;
            }
            $ts = $date['ts'];
            /* Une journée entière commence à minuit : celle d'aujourd'hui est
             * encore à venir. Une heure passée, elle, n'a plus rien à faire ici. */
            if ($ts < $this->_from) {
                if (!$date['allDay'] || date('Y-m-d', $ts) != $today) {
                    continue;
                }
                $ts = $this->_from;
            }
            $this->push($ts, array(
                'category' => 'prevision',
                'title'    => $cmd->getName() . ' — ' . $eqLogic->getName(),
                'detail'   => $cmd->getHumanName(),
                'source'   => 'cmd#' . $cmd->getId(),
                'link'     => $eqLogic->getLinkToConfiguration(),
                'allDay'   => $date['allDay'],
            ));
        }
    }

    /*
     * Une date complète dans une valeur de commande : array('ts', 'allDay'), ou
     * null. Un fuseau écrit dans la valeur (« Z », « +00:00 ») est respecté ;
     * sans fuseau, c'est l'heure locale de Jeedom.
     */
    public static function parseDate($_value) {
        $value = trim((string) $_value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{10}$/', $value)) {
            return array('ts' => (int) $value, 'allDay' => false);
        }
        $zone = null;
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2}(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?)?(?!\d)#', $value, $m)) {
            $y = $m[1];
            $mo = $m[2];
            $d = $m[3];
            $h = isset($m[4]) ? $m[4] : '';
            $mi = isset($m[5]) ? $m[5] : '';
            $zone = (isset($m[6]) && $m[6] !== '') ? $m[6] : null;
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2}))?(?!\d)#', $value, $m)) {
            $d = $m[1];
            $mo = $m[2];
            $y = $m[3];
            $h = isset($m[4]) ? $m[4] : '';
            $mi = isset($m[5]) ? $m[5] : '';
        } else {
            return null;
        }
        if (!checkdate((int) $mo, (int) $d, (int) $y) || ($h !== '' && ((int) $h > 23 || (int) $mi > 59))) {
            return null;
        }
        $allDay = ($h === '');
        try {
            $text = sprintf('%04d-%02d-%02d %02d:%02d:00', $y, $mo, $d, $allDay ? 0 : $h, $allDay ? 0 : $mi);
            $date = ($zone !== null) ? new DateTime($text . ($zone === 'Z' ? '+00:00' : $zone)) : new DateTime($text);
        } catch (Throwable $e) {
            return null;
        }
        return array('ts' => $date->getTimestamp(), 'allDay' => $allDay);
    }
}
