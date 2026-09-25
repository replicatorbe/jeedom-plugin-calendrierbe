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

/*
 * Déroulement des expressions cron, exactement comme Jeedom les exécute.
 *
 * Le coeur décide chaque minute avec cronIsDue() (core/php/utils.inc.php) :
 * contrôle de l'année, checkAndFixCron(), puis Cron\CronExpression::isDue().
 * Appeler isDue() pour chaque minute d'un mois serait exact mais beaucoup trop
 * lent — une quinzaine de tâches « chaque minute », 44 640 minutes chacune.
 *
 * On garde donc l'arbitre du coeur, mais on l'interroge champ par champ : la
 * bibliothèque dit elle-même quelles minutes, quelles heures et quels jours
 * satisfont chaque champ (isSatisfiedBy), avec sa propre grammaire — ses pas
 * plus grands que le champ, ses plages de jours réécrites, ses noms, L, W, #.
 * Réimplémenter cette grammaire à côté donnait un autre résultat que le coeur
 * pour une expression sur trois dans un essai au hasard, et le calendrier
 * annonçait des heures où rien ne part. Il ne reste ici que l'assemblage, qui
 * reprend CronExpression::getRunDate() :
 *  - un champ non « * » doit être satisfait ; une liste l'est si l'un de ses
 *    morceaux l'est ; un morceau qui lève une exception ne l'est pas (isDue()
 *    rend alors faux, le coeur ne lance pas) ;
 *  - jour du mois ET jour de semaine renseignés : l'un OU l'autre suffit, et
 *    « ? » retire son côté de l'alternative.
 *
 * Les deux jours de changement d'heure, entre minuit et 5 h, on revient à la
 * simulation minute par minute de cronIsDue() : HoursField y accepte l'heure
 * voisine, et le coeur bloque les minutes d'heure d'été après 01:59 le jour du
 * retour à l'heure d'hiver. Trois cents appels par tâche, deux jours par an.
 *
 * La bibliothèque est celle du coeur (vendor/dragonmantank/cron-expression).
 */
class calendrierbeCron {

    /* Au-delà, le jour de changement d'heure ne réserve plus de surprise :
     * HoursField ne s'écarte de l'heure affichée que dans l'heure qui suit la
     * transition, à 02:00 ou 03:00. */
    const SLOW_UNTIL_HOUR = 5;

    /* Réplique de checkAndFixCron() : même découpage par espace simple, mêmes
     * refus. Une expression avec deux espaces y perd un champ et n'est jamais
     * lancée ; il faut que le calendrier le voie aussi. */
    public static function fix($_cron) {
        $return = trim((string) $_cron);
        $return = str_replace('*/ ', '* ', $return);
        if (preg_match('/[0-9]*\/\*/', $return)) {
            return '';
        }
        if (preg_match('/\*\/0/', $return)) {
            return '';
        }
        $arrays = explode(' ', $return);
        if (count($arrays) > 5) {
            unset($arrays[5]);
            $return = implode(' ', $arrays);
        }
        return $return;
    }

    /*
     * Prépare une expression. Rend null si le coeur ne peut pas la traiter
     * comme un cron — ce peut être une expression Jeedom, « #[Maison][Soleil]
     * [Lever]# », que l'appelant traite à part.
     */
    public static function parse($_expression) {
        if (is_array($_expression)) {
            return $_expression;
        }
        if (!class_exists('Cron\CronExpression')) {
            return null;
        }
        $raw = trim((string) $_expression);
        if ($raw === '') {
            return null;
        }
        /* Le contrôle d'année de cronIsDue(), fait sur l'expression brute avant
         * checkAndFixCron(), et en comparaison souple comme lui. */
        $schedule = explode(' ', $raw);
        $year = (count($schedule) == 6 && $schedule[5] != '*') ? $schedule[5] : null;
        $fixed = self::fix($raw);
        if ($fixed === '') {
            return null;
        }
        try {
            $factory = new Cron\FieldFactory();
            $expression = new Cron\CronExpression($fixed, $factory);
        } catch (Throwable $e) {
            return null;
        }
        $parts = array();
        for ($position = 0; $position <= 4; $position++) {
            $part = $expression->getExpression($position);
            if ($part !== null && $part !== '*') {
                $parts[$position] = $part;
            }
        }
        $cron = array(
            'raw'        => $raw,
            'year'       => $year,
            'expression' => $expression,
            'factory'    => $factory,
            'parts'      => $parts,
            'minutes'    => array(),
            'hours'      => array(),
        );
        /* Minutes et heures ne dépendent pas du jour, hors changement d'heure :
         * on les demande une fois, sur une date d'hiver sans transition. */
        $reference = new DateTime('2026-01-15 00:00:00');
        for ($m = 0; $m < 60; $m++) {
            $reference->setTime(0, $m, 0);
            if (self::satisfied($cron, 0, $reference)) {
                $cron['minutes'][$m] = true;
            }
        }
        for ($h = 0; $h < 24; $h++) {
            $reference->setTime($h, 0, 0);
            if (self::satisfied($cron, 1, $reference)) {
                $cron['hours'][$h] = true;
            }
        }
        return $cron;
    }

    /* Un champ est-il satisfait à cette date ? Comme getRunDate() : un champ
     * absent ou « * » l'est toujours, une liste l'est par l'un de ses morceaux. */
    private static function satisfied($_cron, $_position, DateTime $_date) {
        if (!isset($_cron['parts'][$_position])) {
            return true;
        }
        $part = $_cron['parts'][$_position];
        $field = $_cron['factory']->getField($_position);
        try {
            if (strpos($part, ',') === false) {
                return $field->isSatisfiedBy($_date, $part, false);
            }
            foreach (array_map('trim', explode(',', $part)) as $listPart) {
                if ($field->isSatisfiedBy($_date, $listPart, false)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            /* isDue() rattrape l'exception et rend faux : le coeur ne lance pas. */
        }
        return false;
    }

    /* Le jour (un DateTime) est-il concerné : année, mois, jour ? */
    private static function dayMatches($_cron, DateTime $_day) {
        if ($_cron['year'] !== null && $_cron['year'] != $_day->format('Y')) {
            return false;
        }
        if (!self::satisfied($_cron, 3, $_day)) {
            return false;
        }
        $parts = $_cron['parts'];
        if (isset($parts[2]) && isset($parts[4])) {
            return ($parts[2] !== '?' && self::satisfied($_cron, 2, $_day))
                || ($parts[4] !== '?' && self::satisfied($_cron, 4, $_day));
        }
        return self::satisfied($_cron, 2, $_day) && self::satisfied($_cron, 4, $_day);
    }

    /*
     * La décision de cronIsDue() pour une minute précise, avec son blocage du
     * jour de retour à l'heure d'hiver. Réservée aux jours de changement
     * d'heure : c'est l'appel exact, et le plus lent.
     */
    private static function dueAt($_cron, $_ts) {
        $moment = new DateTime('@' . $_ts);
        $moment->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $midnight = (clone $moment)->modify('today midnight');
        $tomorrow = (clone $moment)->modify('today midnight +1 day');
        if (($tomorrow->format('I') - $midnight->format('I')) == -1
            && $moment->format('I') == 1 && $moment->format('Gi') > 159) {
            return false;
        }
        if ($_cron['year'] !== null && $_cron['year'] != $moment->format('Y')) {
            return false;
        }
        try {
            return $_cron['expression']->isDue($moment->format('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            return false;
        }
    }

    /*
     * Les exécutions (timestamps) comprises dans [$_from, $_to], dans l'ordre.
     * $_limit borne le résultat : une tâche à la minute sur un an ferait sinon
     * un demi-million d'entrées. Rend null si l'expression n'est pas un cron.
     */
    public static function between($_expression, $_from, $_to, $_limit = 100000) {
        $cron = self::parse($_expression);
        if ($cron === null) {
            return null;
        }
        $out = array();
        if (empty($cron['minutes'])) {
            return $out;
        }
        $hours = array_keys($cron['hours']);
        $minutes = array_keys($cron['minutes']);
        $timezone = new DateTimeZone(date_default_timezone_get());
        $day = new DateTime('@' . $_from);
        $day->setTimezone($timezone);
        $day->setTime(0, 0, 0);
        while ($day->getTimestamp() <= $_to) {
            $midnight = $day->getTimestamp();
            $tomorrow = clone $day;
            $tomorrow->modify('+1 day');
            $tomorrow->setTime(0, 0, 0);
            $end = $tomorrow->getTimestamp();
            if ($end - $midnight == 86400) {
                /* Journée ordinaire : chaque exécution est minuit plus un
                 * décalage, sans construire de date. */
                if (!empty($hours) && self::dayMatches($cron, $day)) {
                    foreach ($hours as $h) {
                        foreach ($minutes as $m) {
                            $ts = $midnight + $h * 3600 + $m * 60;
                            if ($ts < $_from || $ts > $_to) {
                                continue;
                            }
                            $out[] = $ts;
                            if (count($out) >= $_limit) {
                                return $out;
                            }
                        }
                    }
                }
            } else {
                /* Jour de changement d'heure : minute par minute, par
                 * timestamp — le créneau de 02:00 y existe deux fois, ou pas. */
                $dayOk = self::dayMatches($cron, $day);
                for ($ts = $midnight; $ts < $end; $ts += 60) {
                    if ($ts < $_from || $ts > $_to) {
                        continue;
                    }
                    if (!isset($cron['minutes'][(int) date('i', $ts)])) {
                        continue;
                    }
                    $hour = (int) date('G', $ts);
                    if ($hour < self::SLOW_UNTIL_HOUR) {
                        $due = self::dueAt($cron, $ts);
                    } else {
                        $due = $dayOk && isset($cron['hours'][$hour]);
                    }
                    if ($due) {
                        $out[] = $ts;
                        if (count($out) >= $_limit) {
                            return $out;
                        }
                    }
                }
            }
            $day = $tomorrow;
        }
        return $out;
    }

    /* La prochaine exécution strictement après $_after, ou null s'il n'y en a
     * pas dans les $_horizonDays jours. L'horizon couvre un 29 février. */
    public static function next($_expression, $_after, $_horizonDays = 1500) {
        $cron = self::parse($_expression);
        if ($cron === null) {
            return null;
        }
        /* Par tranches d'un mois : une tâche ponctuelle tombe presque toujours
         * dans la première, et on évite de dérouler quatre ans pour rien. */
        $from = $_after + 1;
        $end = $_after + $_horizonDays * 86400;
        while ($from <= $end) {
            $to = min($end, $from + 31 * 86400);
            $found = self::between($cron, $from, $to, 1);
            if (!empty($found)) {
                return $found[0];
            }
            $from = $to + 1;
        }
        return null;
    }

    /* La dernière exécution strictement avant $_before, dans les $_horizonDays
     * jours, ou null. */
    public static function previous($_expression, $_before, $_horizonDays = 31) {
        $cron = self::parse($_expression);
        if ($cron === null) {
            return null;
        }
        $to = $_before - 1;
        $start = $_before - $_horizonDays * 86400;
        while ($to >= $start) {
            $from = max($start, $to - 31 * 86400);
            $found = self::between($cron, $from, $to);
            if (!empty($found)) {
                return end($found);
            }
            $to = $from - 1;
        }
        return null;
    }

    /*
     * Une phrase qui dit la fréquence, pour la tâche répétée qu'on résume au
     * lieu de l'étaler : « toutes les 5 min » se lit mieux que 288 lignes. Elle
     * se déduit des minutes réellement retenues par la bibliothèque, pas du
     * texte : « * /90 » ne part pas toutes les 90 minutes.
     */
    public static function describe($_expression) {
        $cron = self::parse($_expression);
        if ($cron === null || $cron['year'] !== null || count($cron['hours']) != 24
            || isset($cron['parts'][2]) || isset($cron['parts'][3]) || isset($cron['parts'][4])) {
            return '';
        }
        $minutes = array_keys($cron['minutes']);
        sort($minutes);
        $count = count($minutes);
        if ($count == 0) {
            return '';
        }
        if ($count == 60) {
            return 'chaque minute';
        }
        if ($count == 1) {
            return 'chaque heure à :' . sprintf('%02d', $minutes[0]);
        }
        $step = 60 / $count;
        if ($minutes[0] == 0 && is_int($step)) {
            $regular = true;
            foreach ($minutes as $index => $minute) {
                if ($minute != $index * $step) {
                    $regular = false;
                    break;
                }
            }
            if ($regular) {
                return 'toutes les ' . $step . ' min';
            }
        }
        return $count . ' fois par heure';
    }
}
