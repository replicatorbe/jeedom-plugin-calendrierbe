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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin. */
require_once __DIR__ . '/calendrierbeCron.class.php';
require_once __DIR__ . '/calendrierbeCollecte.class.php';

/*
 * Le plugin n'a pas d'équipement : sa page est un calendrier. La classe existe
 * parce que le coeur l'attend (autoload, gestion des plugins), et elle porte le
 * point d'entrée de la collecte.
 */
class calendrierbe extends eqLogic {

    /* Plus de deux mois d'un coup n'a pas de sens à l'écran, et borne le travail
     * d'une requête qu'un navigateur peut envoyer avec n'importe quelles dates. */
    const MAX_RANGE_DAYS = 62;

    public static function events($_from, $_to) {
        $from = (int) $_from;
        $to = (int) $_to;
        if ($to < $from) {
            throw new Exception(__('Période invalide', __FILE__));
        }
        $to = min($to, $from + self::MAX_RANGE_DAYS * 86400);
        $threshold = (int) config::byKey('threshold', 'calendrierbe', 12);
        return calendrierbeCollecte::collect($from, $to, $threshold);
    }
}

class calendrierbeCmd extends cmd {

    public function execute($_options = array()) {
    }
}
