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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/calendrierbe.class.php';

function calendrierbe_install() {
    /* La page est tout le plugin : sans le panneau, il faudrait passer par
     * Plugins > Organisation à chaque fois pour l'ouvrir. On l'ajoute au menu
     * Accueil dès l'installation ; la case reste décochable dans la gestion
     * des plugins. */
    config::save('displayDesktopPanel', 1, 'calendrierbe');
}

function calendrierbe_update() {
}

function calendrierbe_remove() {
    message::removeAll('calendrierbe');
}
